# Sri Puthiyavan Crackers

Website and order-management tools for Sri Puthiyavan Crackers. The project is made up of static HTML pages and assets, a small Python server for local use, and PHP endpoints for hosting environments that execute PHP.

## Contents

| Path | Purpose |
| --- | --- |
| `index.html` | Main storefront and links to the catalogue, price list, and order page |
| `Order.html` | Product browsing and order checkout |
| `quotation.html` | Product quotation tool |
| `invoice.html` | Invoice creation and PDF export |
| `AdminPage.html` | Admin interface for replacing supported site files |
| `AdminMano.html` | Admin interface for viewing submitted order workbooks |
| `products.xlsx` | Product data used by the order, quotation, and invoice pages |
| `catalog.pdf`, `PriceList.pdf` | Customer-facing catalogue and price list |
| `Card1.jpg`, `Card2.jpg`, `Logo.png` | Storefront and business-card artwork |
| `serve.py` | Python static-file server with local order, upload, and invoice helper routes |
| `save-order.php`, `api/` | PHP handlers for saving orders, listing orders, and invoice operations |
| `orderfiles/` | Saved order workbooks |
| `Invoice/<year>/` | Generated invoice PDFs |
| `25092026/`, `OLD/` | Dated and archived site files; not required for the main storefront |
| `server.js` | Separate Express/ExcelJS server example; see the note under [Other server options](#other-server-options) |
| `upload-index.ps1`, `Upload index via FTP.bat` | Existing Windows upload helpers |

## Run locally

### Python server (recommended for local preview)

The Python server serves the site on port `8000` by default and also handles local order saving, supported file uploads, order listing, and an invoice-number helper.

Python **3.11** is recommended. `serve.py` imports Python's `cgi` module, which was removed in Python 3.13; using Python 3.13 or later will fail at startup unless that server code is updated.

From the project directory, run:

```powershell
py -3.11 serve.py
```

If the Python launcher is unavailable, run `serve.py` with the path to your Python 3.11 executable:

```powershell
& 'C:\Path\To\Python311\python.exe' .\serve.py
```

Then open:

- Storefront: <http://localhost:8000/>
- Order page: <http://localhost:8000/Order.html>
- Quotation tool: <http://localhost:8000/quotation.html>
- Invoice tool: <http://localhost:8000/invoice.html>
- Admin file upload: <http://localhost:8000/AdminPage.html>
- Admin order list: <http://localhost:8000/AdminMano.html>

Stop the server with `Ctrl+C`. To use a different port, set the `PORT` environment variable before starting it:

```powershell
$env:PORT = '8080'
py -3.11 .\serve.py
```

The server binds to all network interfaces (`0.0.0.0`), so it may be reachable by other devices on your network. Do not expose it to the public internet.

### PHP hosting

For the complete PHP-backed workflow, deploy the project to a web server configured to execute PHP, preserving the directory structure. The order page tries `save-order.php` first; the invoice page uses the `.php` invoice endpoints; and the order admin uses `api/orders.php`. A static-only server does not execute PHP.

The PHP handlers may require PHP extensions enabled by the host. In particular, `api/orders.php` needs the ZIP extension to read workbook files. Ensure the web-server account can write to `orderfiles/` and `Invoice/` before using order or invoice saving.

## Local server endpoints

`serve.py` implements these routes in addition to serving project files:

| Method | Route | Purpose |
| --- | --- | --- |
| `GET` | `/api/orders` or `/api/orders.php` | List `.xlsx` files in `orderfiles/`, including the order name read from the workbook |
| `GET` | `/api/invoice/next-number` | Return the next invoice number for the current year |
| `POST` | `/save-order` | Save an order workbook from the page's JSON payload |
| `POST` | `/upload` | Replace one of the explicitly allowed root-level files |
| `POST` | `/api/invoice/save-pdf` or `/save-invoice` | Save an uploaded PDF under `Invoice/<year>/` |

The browser pages may request PHP endpoint paths such as `/api/invoice/next-number.php` and `/api/invoice/save-pdf.php`. Use PHP hosting for those exact routes. When those endpoints are unavailable, the invoice page falls back to a browser-generated invoice number and PDF download; this does not save the PDF on the server.

The file upload endpoint accepts only the configured targets and extensions in `serve.py` (`products.xlsx`, `Card1.jpg`, `Card2.jpg`, `catalog.pdf`, `PriceList.pdf`, `index.html`, `Logo.png`, and `AdminMano.html`). Uploads replace the named file in the project root.

## Data and backups

- Products are read in the browser from `products.xlsx`; keep its columns and workbook layout compatible with the site tools when updating it.
- Order checkout creates an Excel workbook and saves it under `orderfiles/`.
- Invoice PDFs are organized by year in `Invoice/<year>/`.
- Back up `products.xlsx`, `orderfiles/`, and `Invoice/` before replacing or deploying site files. These contain the working product catalogue and generated business records.
- The invoice-number helper derives the next number by examining PDF filenames in the current year's invoice folder. Keep invoice filenames consistent with the `SPC` plus number format (for example, `SPC00003.pdf`).

## Other server options

`server.js` is a separate Express server intended to run on port `3000`. It requires the third-party `express` and `exceljs` Node.js packages, but this project currently has no populated `package.json` dependency manifest. The Python server is the documented local option; use the Node server only after provisioning its dependencies and confirming its endpoints match the pages you intend to serve.

## Deployment and safety notes

- The Python server and upload/order endpoints do not provide authentication. Use them only for trusted local development, not as a public production server.
- The admin pages can replace site files and retrieve order records. Restrict access to them on any deployed host (for example, with web-server authentication or network access controls).
- Use HTTPS for any public deployment and apply appropriate permissions and backups to uploaded files and generated records.
- Do not include `smartftp.json` or other credentials in public repositories or deployment artifacts. Review the FTP helper scripts and configuration before running them.
