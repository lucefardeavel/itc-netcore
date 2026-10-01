# Design System Contract

## Direction
Arabic-first RTL, modern ISP operations UI, mobile touch-first while retaining dense desktop power.

## Core components
- AppShell
- DesktopSidebar
- TabletCompactNav
- MobileBottomNav
- TopBar
- Command/Search palette on desktop
- StatCard
- SubscriberCard
- StatusBadge
- MoneyDisplay
- DataTable
- MobileList
- SearchField
- ActionSheet / BottomSheet
- ConfirmSheet
- DangerDialog
- EmptyState
- ErrorState
- OfflineState
- Skeleton
- MoneyInput
- PlanSelector
- CustomerSelector

## Responsive behavior
Desktop tables may provide sort/filter/columns/pagination/bulk/CSV.
Mobile replaces wide tables with cards and a reduced set of prominent filters.

## RTL/LTR
RTL layout for Arabic. Render IP, MAC, usernames, reference IDs and other technical tokens LTR where needed.

## Status language
Statuses use label/icon/text, never color alone. Keep terminology stable across the product: Active, Offline, Expiring, Suspended, Processing, Failed, Reconciliation/Checking.

## Touch/accessibility
Large touch targets, clear spacing for dangerous actions, keyboard/focus support on desktop, real labels, useful contrast, no tiny three-dot targets for primary actions.

## Performance
Do not load large chart/report/network bundles on mobile landing screen. Lazy-load heavy areas.
