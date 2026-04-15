# Gantt Calendar Layout Guide

## Visual Structure

```
┌─────────────────────────────────────────────────────────────┐
│ 📅 Gestion des Congés                    [➕ Ajouter congés]│
└─────────────────────────────────────────────────────────────┘

┌─────────────────────────────────────────────────────────────┐
│ Filters: [Mois ▼] [Année ▼] [Société ▼] [Agence ▼] [Reset] │
└─────────────────────────────────────────────────────────────┘

┌────────────────────────────────────────────────────────────────────────────┐
│ ← Précédent  March 2026  Suivant →                                         │
├────────────────────────────────────────────────────────────────────────────┤
│ Employé    │ 1  │ 2  │ 3  │ 4  │ 5  │ 6  │ 7  │ 8  │ 9  │ 10 │ 11 │ 12 │
│            │ L  │ M  │ M  │ J  │ V  │ S  │ D  │ L  │ M  │ M  │ J  │ V  │
├────────────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┤
│ Alice Dup  │    │[═══════════ Leave Bar ════════════]    │    │    │    │
├────────────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┤
│ Bob Smith  │    │    │    │[═════ Leave Bar ═════]│    │    │    │    │    │
├────────────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┤
│ Carol Lee  │[═══════════════════════════════════════════════════════════]│
├────────────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┼────┤
│ David Park │    │    │    │    │    │[═════════ Leave Bar ════════════]│
└────────────┴────┴────┴────┴────┴────┴────┴────┴────┴────┴────┴────┴────┘

═ = Leave bar (colored, clickable)
L/M/M/J/V/S/D = Day abbreviations (Lundi, Mardi, Mercredi, Jeudi, Vendredi, Samedi, Dimanche)
```

## Responsive Mobile View

```
┌────────────────────────────────────┐
│ 📅 Gestion des Congés             │
│                   [➕ Ajouter]    │
├────────────────────────────────────┤
│ Filters stacked vertically         │
│ [Month ▼]                          │
│ [Year ▼]                           │
│ [Société ▼]                        │
│ [Agence ▼]                         │
│ [Reset Button]                     │
├────────────────────────────────────┤
│ ← M 2026 →  (Date header)         │
├────────────────────────────────────┤
│ Alice Dup  │ [Bar] ──→│ (scrollable)
│ Bob Smith  │ [Bar] ──→│
│ Carol Lee  │ [Bar ──────────] ──→│
│ David Park │[Bar] ──→│
└────────────────────────────────────┘
```

## Bar Styling Details

### Normal Leave (Validé)
```
┌─────────────────────────┐
│ Alice Dupont            │  ← Employee name in bar
└─────────────────────────┘
Background: user.couleur_conges color at 100% opacity
Border: Slightly darker shade
Shadow: 0 1px 3px rgba(0,0,0,0.2)
```

### Pending Leave (En Attente)
```
┌─────────────────────────┐
│ Alice Dupont            │  ← Same but 0.7 opacity
└─────────────────────────┘
Opacity: 0.7 (faded appearance)
Indicates awaiting validation
```

### Hover Effect
- Transform: scaleY(1.15) - slight vertical expansion
- Box-shadow: 0 2px 6px rgba(0,0,0,0.4) - enhanced shadow
- Cursor: pointer

## Dimensions

### Desktop
- Day column width: 40px
- Row height: 48px minimum
- Employee name column: 150px fixed width
- Bar padding: 4px horizontal, 4px vertical
- Font size in bars: 11px (ellipsis if overflow)

### Tablet/Mobile (max-width: 1024px)
- Day column width: 30px
- Row height: 48px (same)
- Employee name column: 150px (same)
- Font size in bars: 9px
- Responsive font in header: 10px

### Scrolling
- Vertical: Content area scrolls if many leaves
- Horizontal: Gantt container scrolls if month > viewport width
- Smooth scrolling enabled

## Color System

### From Database
```php
$userColor = $leave['couleur_conges'];  // If set in users table
```
Example: "#FF6B6B", "#4ECDC4", "#45B7D1", etc.

### Fallback Palette (if couleur_conges is null)
```php
$colorPalette = [
    '#FF6B6B', '#4ECDC4', '#45B7D1', '#FFA07A', '#98D8C8',
    '#F7DC6F', '#BB8FCE', '#85C1E2', '#F8B88B', '#ABEBC6',
    '#F1948A', '#A9DFBF', '#D7BDE2', '#F5B7B1', '#F9E79F',
    '#FADBD8', '#D5F4E6', '#EAFAF1', '#FCF3CF', '#FEF9E7'
];
// Hash: colorPalette[user_id % count($colorPalette)]
```

### Status Styling
- **Validé**: Full brightness, normal opacity
- **En attente**: Same color, opacity: 0.7
- **Refusé**: Not displayed (handled in modal only)

## Date Header Calculations

### Day Display
```
Date: March 7, 2026 (Friday)
Display:
  Number: 7
  Abbreviation: V (Vendredi)
  Width: 40px
  Padding: 8px top/bottom, 4px left/right
```

### Month Navigation
- Previous button: Goes back 1 month (wraps year)
- Next button: Goes forward 1 month (wraps year)
- Current display: Localized month and year (e.g., "March 2026")

## Leave Bar Positioning

### Calculation Example
```
Leave: 2026-03-05 to 2026-03-12 (8 days)
startDay = 5
endDay = 12
barWidth = (12 - 5 + 1) * 40 = 320px

Visual: Bar starts at pixel 160 (4 days * 40px) and spans 320px
```

### Cross-Month Leaves
```
Leave: 2026-02-28 to 2026-03-05 (when viewing March)
Display: Shows as starting on day 1, extending to day 5
(Starts at month boundary)

Leave: 2026-03-28 to 2026-04-05 (when viewing March)
Display: Shows as starting on day 28, extending to day 31
(Ends at month boundary)
```

## Modal Layout

### Leave Detail Modal
```
┌────────────────────────────────┐
│ Détail du congé                │
├────────────────────────────────┤
│ EMPLOYÉ                        │
│ Alice Dupont                   │
│                                │
│ PÉRIODE                        │
│ 2026-03-05 au 2026-03-12       │
│                                │
│ MOTIF                          │
│ Congés payés                   │
│                                │
│ STATUT                         │
│ [validé]  (green)              │
│                                │
│ DATE DE DEMANDE                │
│ 2026-03-01 10:30               │
│                                │
│ COMMENTAIRE                    │
│ (if present)                   │
├────────────────────────────────┤
│           [Fermer]             │
└────────────────────────────────┘
```

## Empty State

When no leaves exist for the selected filters:
```
┌────────────────────────────────────┐
│ ← M 2026 →                         │
├────────────────────────────────────┤
│                                    │
│  📅 Aucune demande de congé pour   │
│     la période sélectionnée        │
│                                    │
│                                    │
└────────────────────────────────────┘
```

## Interaction Flow

1. **View Calendar**
   - Load current month/year
   - Fetch leaves from database
   - Render employee rows with bars

2. **Filter Changes**
   - Select month/year/société/agence
   - Form auto-submits
   - Page reloads with filtered data

3. **Click Leave Bar**
   - showLeaveDetail() function fires
   - Modal appears with full details
   - Click outside or "Fermer" button to close

4. **Add New Leave**
   - Click "➕ Ajouter congés" button
   - Modal opens with form
   - Fill in employee, dates, type, etc.
   - Submit to api/create_conge.php
   - Page reloads on success

## CSS Grid & Flexbox Structure

### Date Header (Flex Row)
```
.gantt-date-header (flex, gap:0)
├─ .gantt-date-label (flex:0 0 150px)
└─ .gantt-dates (flex:1)
   ├─ .gantt-day (flex:0 0 40px) × 31
```

### Leave Row (Flex Row)
```
.gantt-row (flex, align-items:center)
├─ .gantt-row-label (flex:0 0 150px)
└─ .gantt-row-bars (flex:1)
   ├─ .gantt-bar-container (flex:0 0 40px) × 31
   │  └─ .gantt-bar (width: calculated px)
```

Each bar spans multiple containers with position-relative for proper stacking.

## Accessibility Notes

- Semantic HTML (nav, main, aside, header)
- Color not sole indicator (use text labels in bars)
- Keyboard navigation: Tab through bars and buttons
- Tooltips on bars: title attribute shows "Name - Date to Date"
- Modal: Focus trap with click-outside-to-close
- Sufficient color contrast in dark theme

## Print Styles (Future Enhancement)

Could add media query for printing:
- Hide sidebar
- Reduce padding
- Optimize for black & white
- Fit to page width
- Show full dates in header
