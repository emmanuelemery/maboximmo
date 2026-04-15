# 🎯 Leave System Setup Guide

## Problem
The annual leave balance summary (décompte) is not appearing in the monthly PDF export.

## Root Cause
The `conges_soldes` table (which stores annual leave balances) is either:
1. Not created yet (migrations not applied)
2. Empty (no balance data populated)

## Solution

### Automatic Setup (Recommended)
Visit this URL in your browser (as admin):
```
http://localhost/MaBoxImmo2026/public_html/setup_leave_system.php
```

This will:
1. ✅ Check your current system state
2. ✅ Apply all necessary database migrations
3. ✅ Populate leave balance data from existing leaves
4. ✅ Verify everything works

### What the Setup Does

#### Step 1: Check Current State
- Verifies `conges` and `conges_soldes` tables
- Checks for `commentaire_admin` column
- Shows existing leave data count

#### Step 2: Apply Migrations
Runs two SQL migration files:
- `sql/conges_migration.sql` - Creates tables and indexes
- `sql/add_commentaire_admin_conges.sql` - Adds admin comment field

#### Step 3: Populate Data
Calculates leave balances for each user and year:
- Counts days taken in each annual cycle (June N - May N+1)
- Creates entries in `conges_soldes` table
- Uses defaults: 25 days acquired per year, 0 taken initially

#### Step 4: Verify Setup
Confirms:
- All tables created successfully
- Records populated correctly
- System ready for PDF exports

## Database Schema

### conges_soldes Table
```sql
CREATE TABLE conges_soldes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  id_user INT NOT NULL,
  annee INT NOT NULL,
  jours_acquis DECIMAL(5,1) DEFAULT 25.0,
  jours_pris DECIMAL(5,1) DEFAULT 0.0,
  jours_restants DECIMAL(5,1) GENERATED ALWAYS AS (jours_acquis - jours_pris) STORED,
  UNIQUE KEY unique_user_year (id_user, annee),
  FOREIGN KEY (id_user) REFERENCES users(id)
);
```

### Leave Cycle
- **Cycle Year**: June of year N to May of year N+1
- **Example**: For March 2026, cycle is June 2025 to May 2026
- **Calculation**: `cycleYear = (month >= 6) ? year : year - 1`

## What's New in the Leave System

### Recent Fixes
1. **Fixed SQL WHERE clause operator precedence** in `exporter_conges_mois_pdf.php`
   - Now correctly filters archived leaves
   - Properly groups leaves by month

2. **Added commentaire_admin field** to conges table
   - Admin-only comments (not exported in PDF)
   - Visible in the edit modal with orange styling

3. **Fixed half-day (demi-journée) calculation**
   - Correctly handles morning/afternoon selections
   - Properly calculates day count for PDFs

## How to Use

### After Setup
1. Go to [rh_conges.php](http://localhost/MaBoxImmo2026/public_html/rh_conges.php)
2. Select a month and year
3. Click "📄 Export Mois" button
4. PDF should now show decompte section with:
   - Jours acquis (25.0 days)
   - Jours pris (calculated from leaves)
   - Solde restant (acquired - taken)

### Example PDF Output
```
⊡ SOCIETE / AGENCE – Employee Name
  2026-03-16 → 2026-03-16 : CP (1 j)
  2026-03-20 → 2026-03-21 : RTT (2 j)
  Total mois : 3 jours

  Décompte annuel (Juin 2025 - Mai 2026) :
    • Jours acquis : 25.0 j
    • Jours pris : 8.5 j
    • Solde restant : 16.5 j
```

## Files Modified/Created

### New Files
- `setup_leave_system.php` - Interactive setup wizard
- `init_conges.php` - Command-line initialization
- `diagnostic_conges.php` - Database state checker
- `run_migrations_conges.php` - Migration runner
- `populate_conges_soldes.php` - Data population script

### Modified Files
- `exporter_conges_mois_pdf.php` - Fixed WHERE clause bug
- `sql/conges_migration.sql` - Table definitions
- `sql/add_commentaire_admin_conges.sql` - Schema updates

## Troubleshooting

### Issue: "No decompte appears after setup"
**Solution**: Verify the conges_soldes table has data:
- Visit `http://localhost/MaBoxImmo2026/public_html/setup_leave_system.php`
- Run "Check System State" to see record counts
- Run "Populate Data" again if needed

### Issue: "Table already exists" error
**Solution**: This is normal. The setup script ignores this error and continues.

### Issue: "commentaire_admin field not found"
**Solution**: Run setup step 2 again to apply the migration.

## Annual Cycle Dates

The leave system uses French regulatory cycle:
- **Cycle starts**: June 1
- **Cycle ends**: May 31 of next year
- **Examples**:
  - 2025 cycle: June 1, 2025 - May 31, 2026
  - 2026 cycle: June 1, 2026 - May 31, 2027

This means:
- January - May leaves belong to previous year's cycle
- June - December leaves belong to current year's cycle

## Next Steps

After completing setup:
1. Test monthly PDF export: [rh_conges.php](http://localhost/MaBoxImmo2026/public_html/rh_conges.php)
2. Try annual PDF export: [rh_conges_historiq.php](http://localhost/MaBoxImmo2026/public_html/rh_conges_historiq.php)
3. Verify leave data accuracy in database

---

**Setup Access**: [Open Setup Wizard →](http://localhost/MaBoxImmo2026/public_html/setup_leave_system.php)

*Last Updated: 2026-03-26*
