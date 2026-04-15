# Congés Data Import - Complete Guide

## Overview

This directory contains PHP scripts and documentation for importing leave (congés) data from the legacy `conges_data.sql` file into the MaBoxImmo database's new schema.

**Status:** ✅ Import Complete (116/124 records successfully imported)

---

## Quick Start

To re-run the import or import additional data:

```bash
/c/xampp/php/php.exe import_conges_with_report.php
```

This script provides:
- Detailed import statistics
- Field mapping breakdown
- Skipped records with reasons
- Data integrity verification
- Final record count confirmation

---

## Import Scripts

### 1. **import_conges_with_report.php** (Recommended)
   - **Best for:** Final production import with detailed reporting
   - **Features:** Complete report, statistics, error tracking
   - **Output:** Formatted console report with tables
   - **Status:** ✅ Production-ready

### 2. **import_conges_improved.php**
   - **Best for:** Clean import with focused output
   - **Features:** Proper NULL value handling, better SQL parsing
   - **Output:** Concise summary report
   - **Status:** ✅ Tested and working

### 3. **import_conges_final.php**
   - **Best for:** Debugging user validation issues
   - **Features:** User existence checking, skipped record tracking
   - **Output:** Summary with error details
   - **Status:** ✅ Functional (slightly less robust parsing)

### 4. **import_conges_direct.php**
   - **Best for:** Understanding the initial approach
   - **Features:** Simple implementation, clear logic
   - **Output:** Basic statistics
   - **Status:** ⚠️ Less robust (had parsing issues)

---

## Data Mapping Reference

### Type Conge → Motif

```
'CP'      → 'conges_payes'                    (Paid leave)
'RTT'     → 'rtt'                             (Compensatory time off)
'Maladie' → 'maladie_justifiee_deduite'       (Justified illness - deducted)
'Absent'  → 'absence_justifiee_deduite_heures' (Justified absence - deducted hours)
'Autre'   → 'autre_legal_non_deduit'          (Other legal - non-deducted)
```

### Statut Mapping

```
'En attente' → 'en_attente'  (Pending)
'Validé'     → 'validé'      (Approved)
'Refusé'     → 'refusé'      (Rejected)
```

### Field Mapping

```
Old Field          → New Field
date_soumission    → date_demande
validé_par         → id_validateur
(all others)        → (unchanged)
```

---

## Import Results

### Success Rate
- **Total records:** 124
- **Successfully imported:** 116 (93.5%)
- **Skipped:** 7 (5.6%)
- **Errors:** 1 (0.8%)

### Skipped Records (Missing Users)
7 records were skipped because the referenced users no longer exist in the current database:

| User ID | Count | Reason |
|---------|-------|--------|
| 24      | 1     | User not found in database |
| 36      | 4     | User not found in database |
| 38      | 2     | User not found in database |

**Note:** These records can be re-imported if the users are restored to the system.

### Data Distribution

**By Motif:**
- Paid leave (conges_payes): 94 records
- Justified illness (maladie_justifiee_deduite): 11 records
- Other legal (autre_legal_non_deduit): 7 records
- Compensatory time (rtt): 4 records

**By Status:**
- Approved (validé): 90 records
- Pending (en_attente): 26 records

---

## Database Integrity

All imported data has been verified:

✅ **Integrity Checks:**
- No NULL motif values
- No orphaned user references
- No invalid validator references
- 100% valid date ranges (date_debut ≤ date_fin)
- All foreign key constraints satisfied

✅ **Data Quality:**
- 25 unique users affected
- Date range: 2025-07-01 to 2026-05-17
- No duplicate records (ON DUPLICATE KEY UPDATE prevents duplicates)

---

## Database Schema

The import target table structure:

```sql
CREATE TABLE conges (
  id INT(11) NOT NULL PRIMARY KEY,
  id_user INT(10) UNSIGNED NOT NULL,
  date_demande DATETIME NOT NULL,
  date_debut DATE NOT NULL,
  date_fin DATE NOT NULL,
  demi_journee_debut ENUM('non','matin','apres-midi') DEFAULT 'non',
  demi_journee_fin ENUM('non','matin','apres-midi') DEFAULT 'non',
  motif ENUM(...) NOT NULL,  -- See mapping above
  motif_detail VARCHAR(255),
  statut ENUM('en_attente','validé','refusé','archivé') DEFAULT 'en_attente',
  date_validation DATETIME,
  id_validateur INT(10) UNSIGNED,
  commentaire TEXT,
  couleur VARCHAR(7),
  created_at DATETIME,
  updated_at DATETIME,

  FOREIGN KEY (id_user) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (id_validateur) REFERENCES users(id) ON DELETE SET NULL
);
```

---

## Re-import Safety

The import scripts use `ON DUPLICATE KEY UPDATE`, making them **safe to re-run**:

1. **Idempotent:** Running multiple times won't create duplicates
2. **Update-safe:** Existing records are updated with new values
3. **User-safe:** If missing users are added later, records can be re-imported

### To Re-import:

```bash
# Simply run the script again
/c/xampp/php/php.exe import_conges_with_report.php

# Or clear and reimport (if needed):
# DELETE FROM conges WHERE id > 0;
# Then run the import script
```

---

## Troubleshooting

### Issue: "Database connection failed"
**Solution:** Verify MySQL is running and credentials are correct:
- Host: 127.0.0.1
- Database: maboximmo
- User: root
- Password: (empty)

### Issue: "File not found: conges_data.sql"
**Solution:** Ensure you're running from the correct directory:
```bash
cd /c/xampp/htdocs/MaBoxImmo2026
/c/xampp/php/php.exe import_conges_with_report.php
```

### Issue: "Foreign key constraint fails"
**Solution:** This happens when referenced users don't exist. The improved scripts skip these records:
- Check if users 24, 36, 38 exist
- Or modify the scripts to handle differently

### Issue: "Insufficient fields"
**Solution:** A record is malformed. Check the SQL file for encoding issues or special characters.

---

## Code Examples

### Basic Import (One-liner)
```bash
/c/xampp/php/php.exe import_conges_with_report.php
```

### Silent Import (No Output)
```bash
/c/xampp/php/php.exe import_conges_improved.php > /dev/null 2>&1
```

### With Error Logging
```bash
/c/xampp/php/php.exe import_conges_with_report.php 2>&1 | tee import_$(date +%Y%m%d_%H%M%S).log
```

---

## Files Reference

| File | Purpose | Size |
|------|---------|------|
| conges_data.sql | Source data from legacy database | 16.2 KB |
| import_conges_direct.php | Initial import (less robust) | 5.6 KB |
| import_conges_final.php | Final version with validation | 7.8 KB |
| import_conges_improved.php | Improved parsing | 7.3 KB |
| import_conges_with_report.php | Recommended with detailed report | 8.4 KB |
| IMPORT_SUMMARY.md | Executive summary | 4.6 KB |
| IMPORT_README.md | This guide | - |

---

## Post-Import Tasks

### ✅ Completed
- [x] Import 116 records into conges table
- [x] Apply field mappings (type_conge → motif, statut, etc.)
- [x] Validate data integrity
- [x] Verify foreign key constraints
- [x] Document results

### 🔄 Optional Next Steps
1. Run leave balance calculations based on imported data
2. Generate leave reports for management review
3. Archive very old leave records (>2 years)
4. Validate with HR team for accuracy
5. Set up automated leave balance updates

---

## Support & Questions

For issues or questions about the import:

1. Check the IMPORT_SUMMARY.md for results overview
2. Review the specific import script logic
3. Check database integrity with verification queries
4. Look at error messages in the import report output

---

**Last Updated:** 2026-03-26
**Import Status:** ✅ COMPLETE
**Records:** 116 / 124 (93.5%)
