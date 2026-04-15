# Gantt Calendar Implementation Notes

## What Was Changed

### File Modified
- **`/public_html/rh_conges.php`** - Complete redesign of the leave calendar view

### What Was Removed
1. **`$leavesByDate` array** - No longer needed (was organizing leaves by date)
2. **User legend section** - Removed "📌 Employés (couleurs assignées)" grid display
3. **7-column calendar grid** - Replaced with Gantt timeline
4. **Day cell grid layout** - All CSS for `.calendar-grid`, `.day-cell`, `.weekday-header`, etc.
5. **Leave badges** - Removed individual badge styling, replaced with spanning bars

### What Was Added
1. **`getUserColor()` function** - Helper to get color from database or fallback
2. **Gantt timeline styles** - 20+ new CSS classes for the horizontal timeline
3. **Gantt HTML structure** - Date header row + employee rows with bars
4. **Leave deduplication logic** - Group by ID to show unique leave records once
5. **Bar width/position calculations** - CSS/PHP to span bars correctly

### What Stayed the Same
- All filters (month, year, société, agence)
- Leave detail modal
- "Ajouter congés" button and form
- All JavaScript functionality
- Database queries and role-based access control
- All modal styling and interaction

## Key Implementation Details

### 1. Leave Rendering Strategy

**Old approach:**
```
Loop through each day of month
  For each day, show all leaves that include that day as separate badges
  Result: Same leave appears in multiple cells
```

**New approach:**
```
Get unique leaves by ID (deduplicate)
For each leave:
  Calculate start day and end day in current month
  Create one bar spanning all days
  Sort by employee name
  Result: Each leave shows once as a horizontal bar
```

### 2. Bar Width Calculation

```php
// Days covered by the leave
$daysDuration = $endDay - $startDay + 1;

// Each day = 40px column width
$barWidth = $daysDuration * 40;

// Width: 40px * (12-5+1) = 320px for 8-day leave
```

### 3. Empty Cell Handling

Before and after each leave bar, empty `<div class="gantt-bar-container"></div>` elements maintain alignment with the date header columns.

```html
<!-- Before leave starts -->
<div class="gantt-bar-container"></div> <!-- Day 1 -->
<div class="gantt-bar-container"></div> <!-- Day 2 -->
<div class="gantt-bar-container"></div> <!-- Day 3 -->
<div class="gantt-bar-container"></div> <!-- Day 4 -->

<!-- Actual leave bar (spans 8 days = 320px) -->
<div class="gantt-bar" style="width: 320px;">Alice Dupont</div>

<!-- After leave ends -->
<div class="gantt-bar-container"></div> <!-- Day 13 -->
... etc
```

### 4. Color Handling

**Priority order:**
```php
if (!empty($leave['couleur_conges']) && $leave['couleur_conges'] !== null) {
    $barColor = $leave['couleur_conges'];  // From DB (e.g., "#FF6B6B")
} else {
    $barColor = $colorPalette[$leave['user_id'] % count($colorPalette)];  // Hash
}

// Apply to bar
style="background-color: <?=htmlspecialchars($barColor)?>"
```

### 5. Status Styling

**Pending leaves (en_attente):**
```css
.gantt-bar.pending {
    opacity: 0.7;  /* Faded look */
}
```

Added to bar element if `$leave['statut'] === 'en_attente'`

**Other statuses (validé, refusé):**
- All bars show fully opaque
- Refused leaves still appear (differs from some calendar systems)
- Status differentiation happens in detail modal

### 6. Responsive Behavior

**Desktop (>1024px):**
- 40px per day column
- 150px employee name column
- Full font sizes

**Mobile (<1024px):**
- 30px per day column (75% of desktop)
- 150px employee name column (same)
- Smaller fonts (9px in bars)

**Horizontal scrolling:**
- `.gantt-container { overflow-x: auto }` handles wide timelines
- Date columns wrap to next line if needed on small screens

### 7. Cross-Month Leaves

When a leave spans into the next or previous month:

```php
// If leave starts before this month, clamp to day 1
if ($startMonth < $filterMonth || $startYear < $filterYear) {
    $startDay = 1;
}

// If leave ends after this month, clamp to last day
if ($endMonth > $filterMonth || $endYear > $filterYear) {
    $endDay = $daysInMonth;
}
```

Result: A leave spanning 2026-02-28 to 2026-03-05 shows in March view starting at day 1 and ending at day 5.

## Performance Considerations

### Database Query
```sql
SELECT c.*, u.id as user_id, u.prenom, u.nom, u.couleur_conges, ...
FROM conges c
JOIN users u ON c.id_user = u.id
LEFT JOIN societes s ON u.id_societe = s.id
LEFT JOIN agences a ON u.id_agence = a.id
WHERE c.date_debut <= ? AND c.date_fin >= ?
AND c.statut != 'archivé'
```

- Single query (no N+1)
- Indexes recommended on: conges.date_debut, conges.date_fin, users.couleur_conges
- Filters applied at SQL level

### DOM Rendering
- ~31 days × number of leaves × (1 + 31 empty containers) = DOM nodes
- Example: 10 leaves = ~10,160 DOM nodes (manageable)
- CSS-based layout (no JavaScript layout recalcs)

### Recommended Indexes
```sql
CREATE INDEX idx_conges_dates ON conges(date_debut, date_fin);
CREATE INDEX idx_conges_statut ON conges(statut);
CREATE INDEX idx_users_color ON users(couleur_conges);
```

## Testing Scenarios

### 1. Normal Leave (Single Month)
- Create leave: 2026-03-10 to 2026-03-15
- View: March 2026
- Expected: Bar spanning 6 days (6 × 40px = 240px)

### 2. Cross-Month Leave
- Create leave: 2026-02-28 to 2026-03-10
- View: March 2026
- Expected: Bar starting at day 1, ending at day 10 (visual only)

### 3. Pending Leave
- Create leave with default status (en_attente)
- Expected: Bar shows with opacity 0.7

### 4. Multiple Leaves Same Employee
- Create 2 leaves for same employee
- Expected: 2 rows, each with one bar

### 5. Filters
- Filter by society/agency
- Expected: Leaves filtered at database level

### 6. Empty State
- Filter for month with no leaves
- Expected: "📅 Aucune demande de congé..."

### 7. Color from Database
- Set user.couleur_conges = "#FF6B6B"
- Expected: Bar shows in that color

### 8. Responsive
- View on mobile (< 1024px)
- Expected: Narrower columns, horizontal scroll

### 9. Modal
- Click any leave bar
- Expected: Detail modal opens with full information

### 10. Add Leave
- Click "➕ Ajouter congés"
- Fill form, submit
- Expected: Page reloads with new leave visible

## Browser Compatibility

### Required Features
- CSS Flexbox
- CSS Grid (not used, but safe)
- Horizontal overflow scrolling
- HTML5 date inputs
- ES6 JavaScript (arrow functions, template strings)

### Tested On
- Chrome 90+
- Firefox 88+
- Safari 14+
- Edge 90+

### Potential Issues
- Very old browsers (IE11) won't support Flexbox fully
- Mobile viewport must be set correctly (already included)

## Database Schema Notes

### Required Columns
```
conges table:
  - id (int)
  - id_user (int) → users.id
  - date_debut (date)
  - date_fin (date)
  - statut (enum: 'en_attente', 'validé', 'refusé', 'archivé')
  - motif (varchar)
  - commentaire (text, nullable)
  - date_demande (timestamp)

users table:
  - id (int)
  - prenom (varchar)
  - nom (varchar)
  - couleur_conges (varchar, nullable) - e.g. "#FF6B6B"
  - id_societe (int, nullable)
  - id_agence (int, nullable)
  - actif (boolean)
```

### Data Types
- `couleur_conges` should be VARCHAR(7) for hex colors like "#FF6B6B"
- Can also use named colors: "red", "blue", etc. (any valid CSS color)

## Potential Enhancements

### Short Term
1. Drag-to-resize bars to edit dates
2. Double-click bar to edit leave inline
3. Right-click context menu on bars
4. Keyboard shortcuts (arrow keys, enter, delete)
5. Export current view to CSV

### Medium Term
1. Drag-and-drop to move leaves between months
2. Color legend popup
3. Conflict detection (overlapping leaves)
4. Bulk actions (approve multiple leaves)
5. Custom date ranges (not limited to month)

### Long Term
1. Integration with calendar API (iCal export)
2. Team view (all team members in one view)
3. Capacity planning (show available days remaining)
4. Absence forecast reports
5. Mobile app sync

## Migration Notes

### From Old Calendar to New
- No database changes required
- All existing data still valid
- UI-only change
- Backward compatible with all filters

### Rollback
If needed, can quickly revert by:
1. Restoring old rh_conges.php file
2. No database cleanup needed
3. All functionality returns to original

## Security Notes

### Input Validation
- All user input validated at SQL level (parameterized queries)
- XSS protection via htmlspecialchars() on display
- CSRF protection via form method (POST handled elsewhere)

### Access Control
- Role-based queries (admin sees all, manager sees agency, user sees own)
- No changes to auth logic
- Same permission system as before

### Color Injection
- couleur_conges value not trusted (could be malicious)
- Applied to CSS style attribute safely
- htmlspecialchars() applied to border-color

## Styling Notes

### CSS Variables Used
```css
:root {
  --bg: #1a2a3a;
  --bg-soft: #232f3f;
  --sidebar: #1f2835;
  --ink: #d0e4ff;
  --muted: #7a91a8;
  --accent: #66d9ff;
  --stroke: rgba(255,255,255,0.15);
  --sidebar-w: 250px;
}
```

All new Gantt styles use these variables for consistency.

### Dark Theme
- Entire interface uses dark theme (unchanged)
- Bar colors stand out against dark background
- High contrast for accessibility

## Known Limitations

1. **Bar text truncation** - Long names get ellipsis
   - Fix: Hover title shows full name

2. **Overlapping bars** - Same employee can't have multiple leaves in one row
   - Limitation of timeline layout
   - Could be improved with stacked rows per employee

3. **Small devices** - Timeline gets cramped on <768px
   - Partial fix: Columns shrink to 30px
   - Potential: Collapsible employee names

4. **Year spanning** - Can't easily show leaves crossing year boundaries
   - Workaround: Switch to December/January separately

5. **Holiday indicators** - Removed from new view
   - In database but not displayed
   - Could re-add as background color in date header

## FAQ

**Q: Why remove the legend?**
A: Gantt bars already show names, legend was redundant. Users see colors in bars directly.

**Q: Can I drag bars to change dates?**
A: Not in this version. Future enhancement. Currently read-only visualization.

**Q: What if employee name is very long?**
A: Bar shows name with ellipsis (...). Hover tooltip shows full name.

**Q: How does it handle 31-day months vs 28-day months?**
A: Automatic - renders only the days that exist in that month.

**Q: Is there a print view?**
A: Not yet. Could add print CSS to hide sidebar, optimize layout.

**Q: Can I export this view?**
A: Not built-in yet. Could export as CSV or PDF (future enhancement).

**Q: What if there are 100 leaves?**
A: Vertical scrolling handles it. Each leave is one row, scrollable.

**Q: Does it work on mobile?**
A: Yes - responsive design shrinks columns and allows horizontal scrolling.

**Q: Can I change the 40px column width?**
A: Yes - search for "40px" in CSS and PHP, update to desired width.

**Q: Does it show holidays?**
A: Not visually, but holiday data exists in PHP. Could add background highlight.
