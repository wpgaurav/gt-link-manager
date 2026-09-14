# Analytics interactions and documentation verification

The final test build is 1.9.0-rc.7. RC5 added the Privacy Policy Guide link and styled campaign removal buttons. RC6 preloaded breakdowns. RC7 completes button tabs, independent tooltips, chart fitting, the totals modal, the AI-guide settings link and the readme refresh. No stable release was published.

## Final behavior

- All six breakdowns load with the report through one bounded combined query. Real button tabs switch visible panels immediately, with arrow/Home/End/Enter/Space support. Selection is retained in forms and report navigation links. Button switches do not mutate browser history or expose navigable tab URLs to speculative prefetch. A no-JavaScript link fallback remains available.
- Page unavailable has brief hover/focus help. External referrers have separate, unlinked help buttons beside their page-report links. Their tooltip describes a reported source; it does not imply the external site is verified or malicious. Tooltips support Escape and stay outside clipped tables.
- The chart uses the recorded activity period for its x-axis, preserves proportional time gaps, labels that actual period in WordPress time, and centers a lone bucket. Exact totals open in a labelled, scrollable native dialog; Close/Escape restores focus.
- Privacy and data links to WordPress's Privacy Policy Guide, where the GT Link Manager section was confirmed live. Campaign removal controls are styled, aligned and 44px high. Main Settings includes the requested REST API/AI-tools guide in its own section.
- The readme now leads with Advanced Analytics and documents drilldowns, instant tabs, chart/modal behavior, retention, privacy, lifecycle controls, AI/API workflows, editor support and CSV safeguards. Requested ThirstyAffiliates, ClickWhale, Lasso, BetterLinks and GeniusLink names are included as manual-mapping CSV sources; dedicated presets remain LinkCentral and Pretty Links.

## External referrers preserved

The proposed uniuit.com exclusion was cancelled before deployment or data cleanup. No blocklist was saved and no external-referrer records were deleted. Final live checks confirmed uniuit.com and baidu.com remain in the reports, with unchanged analytics preferences and collection enabled.

## Validation

The exact final package passed the integration, timezone, failure, security/settings, page attribution, drilldown and chart suites (63, 7, 12, 33, 26, 24 and 4 checks). PHP coding standards and JavaScript syntax checks passed. Tests cover preload query bounds, every tab's page scope and historical gaps, true chart spacing, single-point centering, WordPress timezone labels and existing analytics lifecycle behavior.

Browser checks confirmed keyboard tab switching, independent help controls, tooltip Escape dismissal, a scrollable 96-row totals popup, focus return, and the requested guide destinations. A live trace initially exposed speculative prefetches on navigable tab links; the final button implementation produced zero network requests when switching tabs. Final live checks confirmed six preloaded panels, one visible panel, fitted time axes, the totals popup and standalone external-referrer help.

Installed files match the packaged source. Settings and analytics preferences were preserved. Private operational evidence and backups remain under ignored build-release/qa and the existing remote backup directories. Full hosting/version/capacity validation remains a stable-release gate.

ZIP SHA-256: `453c5e7fe2a3b1cee31561202b4d7928a8cd824b9e594447fa8e91ae26b3c2ff`.
