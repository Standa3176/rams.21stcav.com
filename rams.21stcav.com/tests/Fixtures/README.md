# Test Fixtures

Binary fixtures used by Phase 14 (Mobile Field View) tests.

## sample.heic

- **Purpose:** Source HEIC file for `HeicImageConverterTest::test_converts_heic_to_jpeg`
  and `InstallTaskPhotoUploadTest::test_heic_converts_to_jpeg`.
- **Source:** Nokia Technologies HEIF public sample —
  `https://nokiatech.github.io/heif/content/images/autumn_1440x960.heic`
  (downloaded 2026-04-20). Public HEIF reference file; contains no personally
  identifying EXIF data.
- **Size:** ~287 KB (293,608 bytes)
- **Format check:** First 32 bytes contain the `ftypmif1` brand with
  `mif1heic` compatibility mark (HEIC format confirmed via `bin2hex` magic
  inspection). The HEIC fixture uses the HEIF `mif1` major brand with `heic`
  compatibility brand, which is the standard form emitted by iOS cameras and
  Apple HEIF samples; HEIC converters detect it via the `ftyp` box.

## sample.jpg

- **Purpose:** Source JPEG for `HeicImageConverterTest::test_jpeg_passthrough`
  and `InstallTaskPhotoUploadTest::test_jpeg_upload_stores_file_and_creates_row`.
- **Source:** Generated on the dev box via PHP GD (`imagecreatetruecolor` +
  `imagejpeg`, 200x200 blue→red gradient, quality 85) on 2026-04-20. No camera
  EXIF, no identifying metadata.
- **Size:** ~2.4 KB (2,466 bytes)
- **Format check:** Magic bytes `ff d8 ff` (JPEG SOI + APP0 marker).

## Adding new fixtures

Keep each under 500 KB; binary files bloat the repo. Real files only — do not
rename a JPEG to `.heic` to pretend it's HEIC (the HEIC→JPEG converter tests
check the `ftyp` magic box). If you have a genuine iPhone HEIC you want to use
instead, scrub EXIF first with:

```
exiftool -all= tests/Fixtures/sample.heic
```

and update this README with the new source.
