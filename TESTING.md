# Manual QA checklist

Run these checks on a disposable staging store with a recent WordPress and WooCommerce installation.

## Setup
- Create simple products with SKUs `DEMO-001`, `DEMO-002`, and `DEMO-003`.
- Set regular prices and enable stock management where appropriate.
- Keep a database backup before testing.

## Preview safety
- Upload `sample-supplier-feed.csv`.
- Verify the preview is displayed.
- Verify product prices and stock have not changed before approval.

## Validation
Create a test CSV with:
- A valid SKU and ordinary price change.
- A price change greater than the configured threshold.
- An unknown SKU.
- A repeated SKU.
- A negative price.
- A non-numeric price.
- A fractional stock quantity.
- A negative stock quantity.

Expected:
- Valid rows are selectable.
- High price-change rows are flagged and unchecked by default.
- Invalid and unknown rows cannot be selected.
- Duplicate rows are rejected.

## Apply
- Select one valid row and apply it.
- Verify the product regular price and stock quantity.
- Verify stock management is enabled.
- Verify an existing sale price is retained when it remains lower than the new regular price.
- Verify an invalid sale price is cleared when it exceeds the new regular price.
- Verify the import summary appears in Recent import activity.

## Compatibility
- Test a variation SKU.
- Test with HPOS enabled.
- Test with HPOS disabled.
- Test as a Shop Manager and as a user without `manage_woocommerce`.
- Verify expired previews cannot be applied.
