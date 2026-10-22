# CSV Import Feature for Academic Calendar

## Overview

The Term Years and Terms administration pages now support bulk import of data via CSV files. This allows you to quickly populate or update your academic calendar structure.

## Term Years Import

**Location:** Administration > Academic Calendar > Term Years

**CSV Format:**
```csv
Term Name,Start Date,End Date,Status,Is Current
2024-2025,2024-07-01,2025-06-30,Active,No
2025-2026,2025-07-01,2026-06-30,Active,Yes
```

**Field Descriptions:**
- **Term Name** (required): Unique identifier for the term year (e.g., "2024-2025", "2025-2026")
- **Start Date** (optional): Academic year start date in YYYY-MM-DD format
- **End Date** (optional): Academic year end date in YYYY-MM-DD format
- **Status** (optional): "Active" or "Inactive" (defaults to Inactive if omitted)
- **Is Current** (optional): "Yes" or "No" - marks this as the current academic year (defaults to No)

**Import Behavior:**
- Existing term years with matching names will be updated
- New term years will be created
- Existing records retain their primary keys (no duplicates created)

**Sample File:** See `docs/sample_term_years_import.csv`

---

## Terms Import

**Location:** Administration > Academic Calendar > Terms

**CSV Format:**
```csv
Term Year Name,Term Name,Start Date,End Date,Status
2024-2025,Fall 2024,2024-08-15,2024-12-15,Active
2024-2025,Spring 2025,2025-01-10,2025-05-10,Active
2025-2026,Fall 2025,2025-08-15,2025-12-15,Active
```

**Field Descriptions:**
- **Term Year Name** (required): Reference to the parent term year (must exist or will be created)
- **Term Name** (required): Name of the term (e.g., "Fall 2024", "Spring 2025", "Summer 2025")
- **Start Date** (optional): Term start date in YYYY-MM-DD format
- **End Date** (optional): Term end date in YYYY-MM-DD format
- **Status** (optional): "Active" or "Inactive" (defaults to Inactive if omitted)

**Import Behavior:**
- If the referenced term year doesn't exist, it will be created automatically
- Existing terms with matching names within the same term year will be updated
- New terms will be created
- Existing records retain their primary keys (no duplicates created)

**Sample File:** See `src/assets/sample_data/normalized_FILE_01_term.csv`

---

## Usage Instructions

1. **Prepare your CSV file** using the format described above
   - Use a plain text editor or spreadsheet software (Excel, Google Sheets, LibreOffice Calc)
   - Ensure the first row contains column headers exactly as shown
   - Save as CSV format (UTF-8 encoding recommended)

2. **Navigate to the administration page**
   - For term years: Administration > Academic Calendar > Term Years
   - For terms: Administration > Academic Calendar > Terms

3. **Click "Import CSV" button** in the card toolbar

4. **Select your CSV file** in the upload modal

5. **Review the results**
   - Success message shows count of imported/updated and skipped records
   - Skipped records indicate missing required fields or malformed data

## Common Issues

- **Upload Failed:** Ensure file size is under server limits (typically 2MB for CSV files)
- **Records Skipped:** Check that required fields (Term Name for term years, both Term Year Name and Term Name for terms) are not empty
- **Date Format:** Use YYYY-MM-DD format only (e.g., 2025-08-15)
- **Encoding:** Save CSV files in UTF-8 encoding to avoid character issues

## Exporting Data

Both admin pages include DataTables export buttons (Copy, CSV, Excel, PDF, Print) for exporting existing data. You can:

1. Export current data using the CSV button
2. Modify the exported file
3. Re-import to update records in bulk

This makes it easy to manage your academic calendar efficiently!
