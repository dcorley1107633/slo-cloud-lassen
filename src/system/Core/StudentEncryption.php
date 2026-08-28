<?php
declare(strict_types=1);

namespace SLOCloud\Core;

/**
 * Student Data Encryption Helper
 * 
 * Handles encryption/decryption of student PII (names and C# numbers).
 * Uses separate encryption key from config secrets (FERPA compliance).
 * 
 * @package SLOCloud\Core
 */
class StudentEncryption
{
    private Encryption $encryption;

    /** @var resource|null Shared application lock held for this object's lifetime. */
    private $rotationLockHandle = null;

    /**
     * Key for the keyed lookup hash (SEC-008), or '' when not configured.
     *
     * Empty is a supported state: an install with no lookup key falls back to
     * the unkeyed hash this class has always computed, so existing installs
     * keep matching their existing rows unchanged after an upgrade. See
     * hashCNumber() and docs/concepts/SECURITY.md for why an install cannot
     * simply start setting this key without a migration -
     * every already-stored digest was computed without it (or with a
     * different one) and would stop matching.
     */
    private string $lookupKey;

    /**
     * Initialize with student data encryption key from config
     *
     * @param Config $config Configuration instance
     * @param bool $coordinateKeyRotation Internal escape hatch for the rotation
     *        worker after it has already acquired the exclusive form of this lock
     * @throws \RuntimeException if student encryption key not configured
     */
    public function __construct(Config $config, bool $coordinateKeyRotation = true)
    {
        if ($coordinateKeyRotation) {
            $this->acquireRotationReadLock();
        }

        try {
            // Read both keys only after acquiring the lock. A request that was
            // waiting for a rotation therefore sees the newly activated keys.
            $key = $config->get('security.student_encryption_key');

            if (empty($key)) {
                throw new \RuntimeException(
                    'Student encryption key not configured in security.student_encryption_key'
                );
            }

            $this->encryption = new Encryption($key);
            $this->lookupKey = (string)($config->get('security.student_lookup_key') ?? '');
        } catch (\Throwable $e) {
            $this->releaseRotationReadLock();
            throw $e;
        }
    }

    /**
     * Prevent key rotation from overlapping a request that encrypts, decrypts,
     * or looks up student data. Rotation takes the exclusive form of this lock.
     */
    private function acquireRotationReadLock(): void
    {
        require_once __DIR__ . '/Security.php';

        $lockDirectory = Security::dataRoot(dirname(__DIR__, 2)) . '/var';
        $handle = @fopen($lockDirectory . '/key_rotation.lock', 'c');
        if ($handle === false) {
            throw new \RuntimeException('Could not open the student key-rotation lock');
        }
        if (!flock($handle, LOCK_SH)) {
            fclose($handle);
            throw new \RuntimeException('Could not acquire the student key-rotation lock');
        }
        $this->rotationLockHandle = $handle;
    }

    private function releaseRotationReadLock(): void
    {
        if (is_resource($this->rotationLockHandle)) {
            flock($this->rotationLockHandle, LOCK_UN);
            fclose($this->rotationLockHandle);
        }
        $this->rotationLockHandle = null;
    }

    public function __destruct()
    {
        $this->releaseRotationReadLock();
    }
    
    /**
     * Encrypt student data
     * 
     * @param string $cNumber Student C# from SIS
     * @param string $firstName Student first name
     * @param string $lastName Student last name
     * @return array ['encrypted' => string, 'hash' => string]
     */
    public function encryptStudentData(string $cNumber, string $firstName, string $lastName): array
    {
        $data = json_encode([
            'c_number' => $cNumber,
            'first_name' => $firstName,
            'last_name' => $lastName
        ], JSON_THROW_ON_ERROR);
        
        $encrypted = $this->encryption->encrypt($data);
        $hash = $this->hashCNumber($cNumber);

        return [
            'encrypted' => $encrypted,
            'hash' => $hash
        ];
    }
    
    /**
     * Decrypt student data
     * 
     * @param string $encrypted Encrypted JSON blob
     * @return array ['c_number' => string, 'first_name' => string, 'last_name' => string]
     * @throws \RuntimeException if decryption fails
     */
    public function decryptStudentData(string $encrypted): array
    {
        $json = $this->encryption->decrypt($encrypted);
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        
        return [
            'c_number' => $data['c_number'] ?? '',
            'first_name' => $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? ''
        ];
    }
    
    /**
     * Generate the lookup value used to find a student row by C#.
     *
     * Keyed with security.student_lookup_key when one is configured. The key is
     * not stored in the database and must be rotated only with the supported
     * migration tool.
     *
     * Falls back to the original unkeyed SHA-256 when no key is configured,
     * byte-for-byte identical to this method's behavior before SEC-008, so an
     * install without the key continues matching its existing rows exactly as
     * before. This fallback is what makes the key safe to introduce without
     * forcing every existing install to migrate immediately. See the
     * class-level $lookupKey doc for why that key cannot simply be added by
     * hand later.
     *
     * @param string $cNumber Student C# from SIS
     * @return string Hex digest (SHA-256 or HMAC-SHA256, 64 hex chars either way)
     */
    public function hashCNumber(string $cNumber): string
    {
        return self::hashCNumberWith($cNumber, $this->lookupKey);
    }

    /**
     * Compute a lookup value under an explicitly supplied key.
     *
     * The rotation tool needs to compute digests under a key that is not the
     * one currently configured, so the algorithm lives here rather than in the
     * instance method. Both paths go through this, which is what guarantees a
     * rotated digest is bit-identical to what the running application will
     * compute for the same C-number once the new key is in place.
     *
     * @param string $cNumber Student C# from SIS
     * @param string $lookupKey Lookup key, or '' for the unkeyed legacy hash
     * @return string Hex digest (64 chars either way)
     */
    public static function hashCNumberWith(string $cNumber, string $lookupKey): string
    {
        if ($lookupKey !== '') {
            return hash_hmac('sha256', $cNumber, $lookupKey);
        }
        return hash('sha256', $cNumber);
    }
    
    /**
     * Check if value appears to be encrypted student data
     * 
     * @param string $value Value to check
     * @return bool True if appears encrypted
     */
    public function isEncrypted(string $value): bool
    {
        return $this->encryption->isEncrypted($value);
    }
}
