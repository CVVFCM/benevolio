# The official CERFA form

`2041-rd_11580-05.pdf` — **CERFA n°11580\*05**, form **2041-RD**, *Reçu des dons et
versements effectués par les particuliers au titre des articles 200 et 978 du code
général des impôts*.

- Source: <https://www.impots.gouv.fr/sites/default/files/formulaires/2041-rd/2023/2041-rd_4298.pdf>
- Revision: the 2023 edition (the file's own `CreationDate` is 6 March 2023)
- Retrieved: 29 July 2026
- A public administrative form, so shipping it here is fine.

## Why it is stamped rather than filled

**It has no form fields.** `pdfinfo` reports `Form: none`; there is no `/AcroForm`, no
`/Widget` and no field names — it was flattened by PDF24 Creator and Ghostscript. So
there is nothing to fill, and `App\Receipt\ReceiptGenerator` renders a transparent A4
layer and presses it on with `qpdf --overlay`.

## If this file is ever replaced

The overlay positions text at fixed millimetre coordinates, so **a new revision moves
every value**. Replacing this PDF means re-measuring the coordinate map in
`App\Receipt\CerfaLayout` and looking at the result, not just running the tests — the
tests can prove a value is present, not that it landed on the right line.

Check the page count and size too: the overlay assumes **2 pages, A4, portrait**, and
stamps page 1.
