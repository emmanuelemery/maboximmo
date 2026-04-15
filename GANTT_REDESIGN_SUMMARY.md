# Gantt-Style Calendar Redesign - rh_conges.php

## Overview
The leave calendar view has been completely redesigned from a 7-day grid view to a horizontal Gantt-style timeline where each leave request appears as a colored bar spanning from its start to end date.

## Key Changes

### 1. **Visual Layout**
- **Old**: Traditional 7-column calendar grid with leave badges stacked in each day cell
- **New**: Horizontal timeline with:
  - Date header row showing days 1-31 as columns (40px wide each)
  - One row per employee with leave request bars
  - Automatic bars extending across multiple columns based on duration

### 2. **Leave Bar Display**
- **Colored bars** span horizontally from `date_debut` to `date_fin`
- **Bar content**: Employee name (prenom nom) inside the bar
- **Color source**: Uses `users.couleur_conges` from database, falls back to palette hash
- **Bar width**: Automatically calculated based on leave duration (40px per day)
- **Pending status**: Shows with 0.7 opacity for "en_attente" leaves
- **Interactive**: Click bar to open detail modal with full leave information

### 3. **Removed Elements**
- ✓ Removed "Légende des utilisateurs" section (was showing color legend grid)
- ✓ Removed day-number badges list
- ✓ Removed 7-column day-cell grid layout
- ✓ Replaced with pure Gantt bars

### 4. **CSS Classes (New)**

```
.gantt-wrapper              - Main container
.gantt-header              - Navigation header
.gantt-nav                 - Month/year navigation buttons
.gantt-container           - Scrollable container
.gantt-timeline            - Column flex container for all rows
.gantt-date-header         - Date column headers
.gantt-date-label          - "Employé" label on left
.gantt-dates               - Container for date columns
.gantt-day                 - Individual day column (40px)
.gantt-day.today           - Highlighted current day
.gantt-day-num             - Day number (1-31)
.gantt-day-name            - Day abbreviation (L, M, M, J, V, S, D)
.gantt-row                 - One row per leave request
.gantt-row-label           - Employee name on left (150px fixed)
.gantt-row-bars            - Container for bars in that row
.gantt-bar-container       - 40px cell that may contain a bar
.gantt-bar                 - Actual leave bar with text
.gantt-bar.pending         - Opacity styling for pending leaves
.gantt-empty               - Message when no leaves exist
```

### 5. **Responsive Design**
- **Desktop**: 40px per day column, full employee names visible
- **Mobile** (max-width: 1024px): 30px per day column, smaller fonts
- **Horizontal scroll**: Timeline scrolls horizontally if it exceeds viewport

### 6. **Filters Maintained**
All original filters remain functional:
- Month selection (dropdown 01-12)
- Year selection (dropdown ±2 years from current)
- Société filter (admin only)
- Agence filter (admin only)
- Reset button to clear filters

### 7. **Leave Details Modal**
- Same modal as before, triggered by clicking a bar
- Shows: Employee name, dates, motif, status, request date, comments
- Status styling: Different colors for en_attente / validé / refusé
- Close button and click-outside-to-close functionality

### 8. **"Ajouter congés" Button**
- Remains in top-right of the topbar
- Opens the same form modal as before
- Allows creating new leave requests with all original fields

### 9. **Color Handling**
New `getUserColor()` function prioritizes:
1. `users.couleur_conges` if set in database
2. Falls back to color palette hash based on user ID
3. Maintains visual consistency with database-stored colors

### 10. **PHP Logic Changes**
- Removed `$leavesByDate` array organization (no longer needed)
- Added `getUserColor()` helper function
- Changed leave iteration to show one row per unique leave record (by ID)
- Grouped leaves by employee and sorted by name
- Calculates bar positioning: `(endDay - startDay + 1) * 40px`
- Handles leaves spanning month boundaries (clamps to 1st and last day of month)

## Technical Details

### Date Header Calculation
```php
// Shows abbreviated day names
$dayOfWeekFr = ['Mon' => 'L', 'Tue' => 'M', 'Wed' => 'M', 'Thu' => 'J',
                'Fri' => 'V', 'Sat' => 'S', 'Sun' => 'D']
```

### Bar Width Calculation
```php
$barWidth = ($endDay - $startDay + 1) * 40;  // 40px per day
```

### Leave Deduplication
Leaves are grouped by ID to prevent duplicates when one leave spans multiple dates in the month view.

### Month Boundary Handling
Leaves extending beyond the current month are visually clamped:
- If start date is before month: display starts at day 1
- If end date is after month: display extends to last day of month

## Browser Compatibility
- Modern browsers (Chrome, Firefox, Safari, Edge)
- CSS Grid and Flexbox support required
- Horizontal scrolling for overflow content

## Performance
- Single pass through leaves for rendering
- Minimal DOM nodes compared to grid view
- CSS-based positioning (no JavaScript layout calculations)

## Files Modified
- `/public_html/rh_conges.php` - Complete redesign of calendar view

## Files Unchanged
- `/public_html/rh_conges_validation.php` - No changes
- `/public_html/rh_conges_historiq.php` - No changes
- Database schema - No changes required
- API endpoints - No changes required

## Testing Checklist
- [x] Filter by month/year
- [x] Filter by société (admin)
- [x] Filter by agence (admin/manager)
- [x] View bars spanning multiple days
- [x] Click bar to open detail modal
- [x] View pending leaves (opacity 0.7)
- [x] View validated leaves (full opacity)
- [x] Empty state message
- [x] Horizontal scroll on mobile
- [x] Color from database (couleur_conges)
- [x] Color fallback to palette

## Future Enhancements
- Drag-to-resize bars to edit dates
- Double-click bar to edit leave
- Color legend popup
- Export to PDF
- Print-friendly layout
- Keyboard navigation (arrow keys between bars)
