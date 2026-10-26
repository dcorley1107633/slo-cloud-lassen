# Microsoft Entra ID Single Sign-On Setup

This guide connects SLO Cloud to Microsoft Entra ID (formerly Azure Active Directory) so staff can sign in to the dashboard with their Microsoft account. It uses SAML 2.0.

Read this first:

- **Entra decides who may sign in.** Set **Assignment required?** to **Yes** and assign only the people or group who should use SLO Cloud (section 3). SLO Cloud does not check groups itself; it trusts Entra to send only assigned people, then signs in those who have an active SLO Cloud account.
- **Dashboard only.** Single sign-on replaces the password for the administration dashboard. Faculty and students who come from Canvas still use LTI, which is separate.
- **Accounts must exist, unless you turn on auto-provisioning.** By default a person signs in only if their Microsoft email matches the email on an **active** SLO Cloud user, so create users first (Administration, Users). Section 12 describes the optional setting that creates a non-admin account at first sign-in.
- **Sign-in starts at SLO Cloud.** The identity provider cannot start a sign-in by itself. Users start from the SLO Cloud login page, the direct link in section 9, or a My Apps tile that points to it. Entra's own **Test** button does not work (see section 8).
- **Password login stays.** The password form and the emergency administrator account keep working, so a SAML mistake cannot lock you out.

Time needed: about 30 minutes. Do it in a maintenance window or with a second administrator signed in, until you have tested.

---

## 1. What you need

| Item | Detail |
|---|---|
| Entra role | Cloud Application Administrator, Application Administrator or Global Administrator |
| SLO Cloud role | Administrator, to open Administration, Configuration |
| SLO Cloud address | An HTTPS address users can reach, for example `https://slo.example.edu/`; it goes in **Site Address**. A plain `http://` address is refused (except `http://localhost` for testing). |
| Server clock | Synchronized with NTP. Sign-in fails if the clocks differ by more than about a minute. |
| PHP | The `openssl`, `dom`, `libxml` and `zlib` extensions (all standard; see System Requirements). |
| Users | Each person's SLO Cloud email equals the email Entra sends (section 5). |

Throughout this guide `https://slo.example.edu/` stands for your SLO Cloud address. If SLO Cloud is installed in a folder, include it everywhere (for example `https://example.edu/slo/saml/acs.php`).

---

## 2. Get the SLO Cloud values

Entra needs four addresses. SLO Cloud builds them for you from the address people use to reach it:

1. Sign in to SLO Cloud as an administrator and open **Administration**, **Configuration**.
2. In the card **Single Sign-On (SAML) Settings**, enter **Site Address**, for example `https://slo.example.edu`. Use only the address (https, no folder, no trailing path). If SLO Cloud is installed in a folder, that comes from **Base URL Path** at the top of the page and is added for you.
3. Leave **Enable SAML SSO** off. Select **Save**.
4. The top of the card now shows **Copy these into your identity provider**. Keep this page open; you will copy from it in section 4. Each value is named after the Entra field it goes in:

   - Identifier (Entity ID)
   - Reply URL (Assertion Consumer Service URL)
   - Sign on URL
   - Logout Url
   - Metadata file (for Upload metadata file)

   They look like `https://slo.example.edu/saml/acs.php`. Entra and SLO Cloud compare them character by character, so copy them rather than retyping, and use the same host name in the browser as in **Site Address**.

The three override boxes under **Advanced** at the bottom of the card stay blank. They exist only for installations where the identity provider already has different addresses registered.

The Entity ID is only an identifier. It happens to be the address of SLO Cloud's metadata page, but Entra does not need to reach it.

---

## 3. Create the enterprise application in Entra

1. Sign in to the Microsoft Entra admin center at <https://entra.microsoft.com>.
2. In the left menu open **Entra ID**, then **Enterprise apps**. (In older portals this is **Azure Active Directory**, then **Enterprise applications**.)
3. Select **New application**, then **Create your own application**.
4. Name it, for example `SLO Cloud`.
5. Choose **Integrate any other application you don't find in the gallery (Non-gallery)** and select **Create**. Wait for the application's overview page to open.

### Decide who may sign in (required)

6. In the application's left menu select **Properties**.
7. **Required:** set **Assignment required?** to **Yes**. This is what limits who can use SLO Cloud. With it on, only the people and groups you assign can sign in, and everyone else gets a Microsoft error before SLO Cloud sees them. With it off, anyone in your tenant can reach SLO Cloud (and, with auto-provisioning on, get an account). Do not leave it off.
8. Optional: set **Visible to users?** to **No** if you do not want an SLO Cloud tile in My Apps. Select **Save**.
9. In the left menu select **Users and groups**, then **Add user/group**. Pick the people or a security group (groups need Entra ID P1 or higher; otherwise assign people one at a time). Select **Assign**.

Assigning someone here lets them sign in at Microsoft. They still need an active SLO Cloud user with the same email.

---

## 4. Set up single sign-on (SAML)

1. In the application's left menu select **Single sign-on**, then the **SAML** tile.
2. In the box **Basic SAML Configuration** select **Edit** and fill in:

   | Entra field | Enter |
   |---|---|
   | Identifier (Entity ID) | the Identifier (Entity ID) from section 2. Select **Add identifier**, and mark it as **Default**. |
   | Reply URL (Assertion Consumer Service URL) | the Reply URL from section 2. Select **Add reply URL**. |
   | Sign on URL | the Sign on URL from section 2 |
   | Relay State (Optional) | leave empty |
   | Logout Url (Optional) | the Logout Url from section 2 (leave empty if you do not want single logout) |

3. Select **Save**, then close the panel. If Entra offers a test, skip it for now.

**Shortcut:** instead of typing the values, open `https://slo.example.edu/saml/metadata.php` in a browser (it works as soon as **Site Address** is saved), save the page as `slo-metadata.xml`, and on the Entra page select **Upload metadata file** at the top and upload it. Then check that Identifier, Reply URL and Logout Url came through correctly, and add the Sign on URL by hand.

---

## 5. Set the attributes and claims

SLO Cloud signs a person in by **email**, so Entra must send an email address that matches the SLO Cloud account.

1. In the **Attributes & Claims** box select **Edit**.
2. Under **Required claim**, leave **Unique User Identifier (Name ID)** as it is. SLO Cloud does not use it to find the account when an email claim is present.
3. Under **Additional claims**, find the claim named
   `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress`. By default its **Source attribute** is `user.mail`.
   - If every SLO Cloud user's email is the same as their Microsoft sign-in name (UPN), change the source attribute to `user.userprincipalname`. Select the claim, then set **Source attribute** and **Save**.
   - Otherwise leave `user.mail`. People whose Microsoft account has an empty **Email** field send no email claim and cannot sign in until it is filled (see Troubleshooting).
4. Optional, for the display name: select **Add new claim** and enter
   - **Name:** `displayname`
   - **Namespace:** `http://schemas.microsoft.com/identity/claims`
   - **Source:** Attribute, **Source attribute:** `user.displayname`

   then **Save**. SLO Cloud only reads the name; it does not use it to sign in.
5. Close the panel.

The claim names you rely on are shown in the claims table. Copy them exactly into SLO Cloud in section 6.

---

## 6. Collect the Entra values and enter them in SLO Cloud

### 6a. Entra values

On the **Single sign-on** page:

1. In the box **SAML Certificates**, next to **Certificate (Base64)** select **Download**. This is a `.cer` file. Open it in a text editor (Notepad); it is text that starts with `-----BEGIN CERTIFICATE-----`.
2. Still in **SAML Certificates**, select **Edit** and check:
   - **Signing Option:** `Sign SAML assertion` (the default) or `Sign SAML response and assertion`. Either works. Not `Sign SAML response` alone.
   - **Signing Algorithm:** `SHA-256` (the default). SLO Cloud refuses SHA-1.
   - **Notification Email Addresses:** an address that someone reads, so you hear before the certificate expires.
   - Select **Save**.
3. In the box **Set up SLO Cloud** (it carries your application's name) copy the three values:

   | Entra shows | SLO Cloud setting |
   |---|---|
   | **Login URL** | Login URL (IdP SSO URL) |
   | **Microsoft Entra Identifier** | Microsoft Entra Identifier (IdP Entity ID) |
   | **Logout URL** | Logout URL (IdP SLO URL), only if you want single logout |

   The Entra Identifier looks like `https://sts.windows.net/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx/` and must be copied **with** its trailing slash.

### 6b. SLO Cloud settings

1. Sign in to SLO Cloud as an administrator and open **Administration**, **Configuration**.
2. Find the card **Single Sign-On (SAML) Settings** and fill in:

   | SLO Cloud field | Value |
   |---|---|
   | Enable SAML SSO | leave **off** until section 7 |
   | Microsoft Entra Identifier (IdP Entity ID) | Microsoft Entra Identifier (with trailing slash) |
   | Login URL (IdP SSO URL) | Login URL |
   | Logout URL (IdP SLO URL) | Logout URL from Entra, or empty for no single logout |
   | Certificate (Base64): IdP signing certificate | the full text of the downloaded certificate |
   | Certificate (Base64): rollover | empty for now (see section 11) |
   | IdP Display Name | `Microsoft` (shown as "Login with Microsoft") |
   | Site Address | already entered in section 2 |
   | The three override boxes under **Advanced** | leave blank |
   | Email Attribute Name | `http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress` (the default; `emailaddress` also works) |
   | Full Name Attribute Name | empty (the default), or `http://schemas.microsoft.com/identity/claims/displayname` if you added that claim. Without it, an account created at sign-in is named by its email until an administrator renames it. |
   | Auto-Provision Accounts, Auto-Provision Email Domains | off and empty (see section 12) |
   | Force Re-Authentication | off |
   | Require Signed Response | **on** only if you chose `Sign SAML response and assertion` |
   | Sign Requests, Require Encrypted Assertions | off (see section 12) |

3. Select **Save**. Settings are checked when **Enable SAML SSO** is ticked: if a value is unusable nothing is saved and the message says which (for example "Certificate (Base64): IdP signing certificate is not a valid X.509 certificate").

If you prefer to edit `config\config.yaml`, the same section looks like this (restart is not needed):

```yaml
saml:
  enabled: false
  site_url: "https://slo.example.edu"
  idp_entity_id: "https://sts.windows.net/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx/"
  idp_sso_url: "https://login.microsoftonline.com/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx/saml2"
  idp_slo_url: "https://login.microsoftonline.com/xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx/saml2"
  idp_cert: |
    -----BEGIN CERTIFICATE-----
    MIIC8DCCAdigAwIBAgIQ...
    -----END CERTIFICATE-----
  idp_name: "Microsoft"
  attr_email: "http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress"
  attr_name: "http://schemas.microsoft.com/identity/claims/displayname"
```

---

## 7. Turn it on and test

1. Open a **private (incognito) browser window** so you are not signed in to SLO Cloud. Keep your normal administrator session open in another window.
2. In your administrator window, go to **Administration**, **Configuration**, tick **Enable SAML SSO** and **Save**. If settings are incomplete the save is refused and the message lists what is missing.
3. In the private window open `https://slo.example.edu/login.php`. A **Login with Microsoft** button appears under the password form.
4. Select it. You go to Microsoft, sign in (with MFA if your tenant requires it) and land on the SLO Cloud dashboard.
5. Repeat with a person who is **not** assigned to the Entra application and one who has no SLO Cloud account:
   - Not assigned: Microsoft shows its own error and SLO Cloud is never reached.
   - Assigned, no matching SLO Cloud user (auto-provisioning off, or the email is at an unlisted domain): SLO Cloud shows **Account Not Found** with the email Microsoft sent.
6. In SLO Cloud open **Administration**, **Activity Log**. Each attempt appears there as "Signed in with single sign-on" or as a failure with its reason.
7. If you set a Logout URL, select **Sign out** in SLO Cloud and confirm that you end up at Microsoft's sign-out page and then back at the SLO Cloud login page.

---

## 8. Why Entra's Test button fails

The **Test** (or **Test sign in**) button on the Entra application page starts the sign-in at Microsoft and posts the result to SLO Cloud without SLO Cloud asking for it. SLO Cloud refuses such responses on purpose: it only accepts an answer to a sign-in it started, once, within 10 minutes. This blocks captured responses being replayed and sign-ins started by anyone else.

So the Test button lands on a page titled **Sign-in Not Started Here** that begins "You have arrived here unsolicited". That is expected, and the page tells you to test from within the app: always test from the SLO Cloud login page.

---

## 9. Give users a way in

- **Login page:** `https://slo.example.edu/login.php`, button **Login with Microsoft**.
- **Direct link:** `https://slo.example.edu/saml/sso.php` goes straight to Microsoft. Use it in bookmarks and staff pages.
- **My Apps tile:** because you filled in **Sign on URL**, the tile should send people to the direct link. If a tile ever lands on the "sign-in expired or was not started here" page, hide it (Properties, **Visible to users?** = No) and share the direct link instead.

---

## 10. Troubleshooting

Where to look: **Administration, Activity Log** for the short reason, and the log files in the application's `logs` folder (default `D:\SLO Cloud 5\data\logs`) for the detail. The detail is never shown in the browser.

### What the user sees in SLO Cloud

| Message | Cause and fix |
|---|---|
| No "Login with Microsoft" button | SAML is not enabled, or **Enable SAML SSO** did not save. Check Configuration. |
| "SAML SSO is not fully configured" | A required field is empty or invalid. Open Configuration and select **Save**; the message lists the problem. Check the log file for "configuration is not usable". |
| **Sign-in Not Started Here** ("You have arrived here unsolicited") | The sign-in began at Microsoft, not at SLO Cloud: the Entra Test button or a My Apps tile that does not start at SLO Cloud (section 8, section 9). Start from the login page. |
| **Sign-in Expired** | The sign-in was already used (browser back button, a refresh), left open more than 10 minutes, or the data folder (`var\saml`) is not writable by the application. Start again from the login page. |
| **Sign-in Could Not Be Validated** | The page says what failed and lists the values to compare (for example the Entity ID SLO Cloud expects and the one the response carried). The Activity Log shows a matching reason, and the log file has the technical detail. The next table lists the causes. |
| **Account Not Found** | The email Microsoft sent has no SLO Cloud user, and auto-provisioning is off or the email's domain is not listed. Compare the email shown on the page with the user list. Check section 5 (which Entra attribute holds the email). |
| "Your account has been deactivated" | The SLO Cloud user is inactive. Reactivate it in Users. |

### Reasons in the log file

| Log text | Meaning and fix |
|---|---|
| `Signature validation failed` | The certificate in SLO Cloud is not the one Entra signed with. Re-download **Certificate (Base64)** and paste it again. After a certificate rollover, see section 11. |
| `The Assertion of the Response is not signed` | **Signing Option** in Entra is set to sign only the response, or neither. Choose `Sign SAML assertion` or `Sign SAML response and assertion`. |
| `The Message of the Response is not signed and the SP requires it` | **Require Signed Response** is on in SLO Cloud but Entra only signs the assertion. Turn the setting off, or change Entra's Signing Option. |
| `Response uses a SHA-1 signature` | Set **Signing Algorithm** to SHA-256 in Entra. |
| `Invalid audience` | Entra's **Identifier (Entity ID)** differs from the Identifier SLO Cloud shows under Values to enter at the identity provider. Copy it again. |
| `Invalid issuer` | **Microsoft Entra Identifier (IdP Entity ID)** in SLO Cloud is not Entra's **Microsoft Entra Identifier**. Copy it again, including the trailing slash and the correct tenant. |
| `The response was received at ... instead of ...`, `A valid SubjectConfirmation was not found` | The **Reply URL** in Entra differs from the Reply URL SLO Cloud shows, or users reach SLO Cloud by a different host name than **Site Address**. Make them identical and use that host name in the browser. |
| `Could not validate timestamp: expired` / `not yet valid` | The server clock is wrong. Fix time synchronization on the SLO Cloud web server. |
| `The InResponseTo of the Response ... does not match` | The response is for another sign-in. Start again from the login page. |
| `Response has no usable email address` | Entra sent no email claim. Fill in **Email** on the user in Entra, or change the claim's source attribute to `user.userprincipalname` (section 5), and check **Email Attribute Name** is the claim's full name or the short name Entra shows (`emailaddress`). |
| `Unable to create the SAML request folder` | The web server account cannot write to `<data>\var`. Give it Modify access there. |

### Errors shown by Microsoft

| Error | Fix |
|---|---|
| `AADSTS50011` (reply URL does not match) | **Reply URL** in Entra must equal the Reply URL SLO Cloud shows (section 2). |
| `AADSTS50105` (user not assigned) | Assign the person in Users and groups (section 3), or set **Assignment required?** to No. |
| `AADSTS75011` (authentication method mismatch) | Should not occur: SLO Cloud does not ask for a particular sign-in method. If it appears, report it. |
| `AADSTS700016` (application not found) | Entra's **Identifier** differs from the Identifier SLO Cloud shows, or the application is in a different tenant. |

---

## 11. Certificate rollover (before the certificate expires)

Entra's SAML signing certificate expires (by default after three years) and Entra emails the address you set in section 6a. When it changes, sign-in breaks unless SLO Cloud knows the new certificate. SLO Cloud accepts two certificates at once so there is no outage:

1. In Entra, open the application, **Single sign-on**, **SAML Certificates**, **Edit**, and select **New Certificate**, then **Save**. The new certificate is **inactive**.
2. Select the three dots next to the new certificate and **Base64 certificate download**.
3. In SLO Cloud, Configuration, paste the new certificate into **Certificate (Base64): rollover** and **Save**. SLO Cloud now accepts responses signed with either certificate.
4. In Entra, select the three dots next to the new certificate and **Make certificate active**. Confirm.
5. Test a sign-in.
6. In SLO Cloud, move the new certificate into **Certificate (Base64): IdP signing certificate**, clear the rollover box and **Save**.

---

## 12. Optional hardening

- **Conditional Access and MFA.** Apply them to the `SLO Cloud` application in Entra (Protection, Conditional Access). SLO Cloud does not ask for the method, so whatever Entra requires is honored.
- **Signed requests and encrypted assertions** (Sign Requests, Require Encrypted Assertions): normally unnecessary because all traffic is HTTPS and the assertion is already signed. If your policy requires them, create a key pair, keep the key outside the web root, and fill in **SP Certificate** and **SP Private Key File** in SLO Cloud:

  ```
  openssl req -x509 -newkey rsa:3072 -sha256 -days 1095 -nodes -keyout sp.key -out sp.crt -subj "/CN=slo.example.edu"
  ```

  Store `sp.key` beside `key.yaml` (readable by the application account only), then upload `sp.crt` to Entra: **Single sign-on**, **Verification certificates** (signed requests), or the application's **Token encryption** page (encrypted assertions). These Entra features may need a Microsoft Entra ID P1 license; check yours. Only turn the SLO Cloud options on after the certificate is uploaded in Entra.
- **Auto-provisioning.** Turn on **Auto-Provision Accounts** and list the allowed email domains (for example `example.edu`) to have SLO Cloud create an account the first time someone signs in. The account is active and has **no admin role**, which is the read and export tier: the person can see and export everything the dashboard shows but cannot change data. Make people administrators in Users. A domain does not include its subdomains. Existing and deactivated accounts are never changed, so deactivating a user still blocks them. The domain list is only a second check: Entra's **Assignment required?** (section 3) is what limits who can reach SLO Cloud, so keep it set to **Yes** and assign only the people or group who should have read access. The Activity Log shows each creation as "Account created automatically at first single sign-on".
- **Force Re-Authentication** makes Microsoft ask for credentials every time even with an active Microsoft session. Leave it off unless required.
- **Removing access.** To stop someone signing in with Microsoft, unassign them in Entra or deactivate their SLO Cloud user. Deactivating the SLO Cloud user is enough on its own.
- **Turning SSO off.** Untick **Enable SAML SSO** in Configuration. The button disappears; nothing else changes.

---

## 13. Checklist

- [ ] Site Address saved; the four values copied from SLO Cloud, same host name as in the browser
- [ ] Enterprise application created, **Assignment required?** = Yes, only the right people or group assigned
- [ ] Basic SAML Configuration: Entity ID, Reply URL, Sign on URL (and Logout Url)
- [ ] Email claim confirmed; users' SLO Cloud emails match it
- [ ] Signing algorithm SHA-256; Certificate (Base64) downloaded
- [ ] SLO Cloud Configuration filled in and saved; SAML enabled
- [ ] Tested from the login page in a private window: assigned user with account, assigned user without account, unassigned user
- [ ] Single logout tested (if used)
- [ ] If auto-provisioning is on: domains listed, Entra assignment limited, a test sign-in created a non-admin account
- [ ] Certificate expiry notification address is monitored; rollover steps (section 11) filed with your runbook
