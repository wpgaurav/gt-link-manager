# Diagnostics URL wrapping verification

Version 1.9.0-rc.11 constrains diagnostic tables to their card width and allows long URLs, code and other values to wrap. Header cells keep a readable proportion on narrow screens; text is not truncated or modified.

A disposable fixture reproduced the problem using a long unbroken URL in the saved diagnostics. Before the fix, the table expanded beyond the viewport. With the packaged CSS, both diagnostic tables stayed inside their cards at desktop and 390px widths, and document width matched the viewport. The actual long URL on Gatilab was visually checked after deployment and wraps fully inside the card.

Package comparison against the tested RC10 build confirmed only admin CSS, plugin version and readme changed. PHP syntax, whitespace and source/ZIP parity passed. Existing runtime tests were not repeated for this CSS-only behavioral change. Both sites were backed up and updated; installed file hashes and live data/settings checks passed. No public release was created.

ZIP SHA-256: `b9a1c2b636faa5407a1dada698ddd9498fc3f60e8c7aa95af8ab10c6f8e5be16`.
