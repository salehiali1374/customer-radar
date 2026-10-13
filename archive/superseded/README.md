# Superseded archives - NOT for release

These ZIP files are kept for provenance only. They are **not** release candidates and must not be uploaded, installed on a live shop or copied back into `dist/`.

| File | What it is | SHA-256 |
|---|---|---|
| `customer-radar-1.0.0.zip` | The original 1.0.0 archive, preserved byte for byte (never overwritten). It predates the Phase 4.1 / 4.2 fixes (opt-out boundary, privacy eraser, uninstall policy, clone protection, net-sales definitions). | see `SHA256SUMS.txt` |
| `customer-radar-1.0.0-rc1.zip` | The Phase 4.1 candidate. Superseded by `1.0.1-rc1` (Phase 4.2). | see `SHA256SUMS.txt` |

`dist/` is the release-facing folder: it holds only what `bin/build-zip.py` produced from the current tree. Verify any archive with
`python3 bin/build-zip.py --verify <zip>`; an old archive is expected to report itself as **stale** against the working tree.
