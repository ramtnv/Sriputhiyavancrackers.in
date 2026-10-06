import json
import os
import re
from cgi import FieldStorage
from datetime import datetime
from http.server import ThreadingHTTPServer, SimpleHTTPRequestHandler
from pathlib import Path
from urllib.parse import unquote, urlparse

SITE_ROOT = Path(__file__).resolve().parent
ORDER_DIR = SITE_ROOT / 'orderfiles'
ORDER_DIR.mkdir(exist_ok=True)
INVOICE_ROOT = SITE_ROOT / 'Invoice'
INVOICE_ROOT.mkdir(exist_ok=True)

ALLOWED_FILE_TYPES = {
    'products.xlsx': ['.xlsx', '.xls'],
    'Card1.jpg': ['.jpg', '.jpeg', '.png'],
    'Card2.jpg': ['.jpg', '.jpeg', '.png'],
    'catalog.pdf': ['.pdf'],
    'PriceList.pdf': ['.pdf'],
    'index.html': ['.html', '.htm'],
    'Logo.png': ['.png'],
    'AdminMano.html': ['.html', '.htm'],
}


def get_next_invoice_number(year=None):
    target_year = int(year or datetime.now().year)
    invoice_dir = (INVOICE_ROOT / str(target_year)).resolve()
    invoice_dir.mkdir(parents=True, exist_ok=True)

    highest = 0
    for pdf_path in invoice_dir.glob('*.pdf'):
        match = re.search(r'(\d+)', pdf_path.stem)
        if not match:
            continue
        try:
            value = int(match.group(1))
            highest = max(highest, value)
        except ValueError:
            continue

    next_number = highest + 1
    return f'SPC{next_number:05d}'


class Handler(SimpleHTTPRequestHandler):
    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(SITE_ROOT), **kwargs)

    def _safe_file_path(self, request_path):
        if not request_path or request_path == '/':
            return None

        raw_path = unquote(urlparse(request_path).path)
        if raw_path in ('', '/'):
            return None

        candidate = (SITE_ROOT / raw_path.lstrip('/')).resolve()
        try:
            candidate.relative_to(SITE_ROOT.resolve())
        except ValueError:
            return None

        if candidate.is_dir():
            return None

        return candidate

    def _handle_file_upload(self, file_path):
        try:
            allowed_types = ALLOWED_FILE_TYPES.get(file_path.name)
            if not allowed_types:
                return False, 'This file target is not allowed on the server.', 403

            content_type = self.headers.get('Content-Type', '')
            if not content_type.startswith('multipart/form-data'):
                return False, 'Unsupported content type.', 400

            form = FieldStorage(
                fp=self.rfile,
                headers=self.headers,
                environ={'REQUEST_METHOD': 'POST', 'CONTENT_TYPE': content_type}
            )

            if 'replacement' not in form:
                return False, 'No uploaded file field found.', 400

            field = form['replacement']
            uploaded_name = getattr(field, 'filename', '') or ''
            if not uploaded_name:
                return False, 'No file selected.', 400

            uploaded_ext = Path(uploaded_name).suffix.lower()
            if uploaded_ext not in allowed_types:
                allowed_desc = ', '.join(allowed_types)
                return False, f'Invalid file type for {file_path.name}. Expected: {allowed_desc}', 400

            data = field.file.read()
            if data is None or len(data) == 0:
                return False, 'Uploaded file is empty.', 400

            file_path.parent.mkdir(parents=True, exist_ok=True)
            file_path.write_bytes(data)

            return True, {'success': True, 'message': f'File replaced: {file_path.name}'}, 200
        except Exception as exc:
            return False, f'Upload failed: {exc}', 500

    def do_GET(self):
        if self.path == '/api/invoice/next-number':
            year = datetime.now().year
            invoice_no = get_next_invoice_number(year)
            self.send_response(200)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps({
                'invoiceNo': invoice_no,
                'year': year,
                'source': 'server-folder'
            }).encode('utf-8'))
            return

        self.send_response(404)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(json.dumps({'success': False, 'message': 'Not found'}).encode('utf-8'))

    def do_POST(self):
        if self.path in ('/api/invoice/save-pdf', '/save-invoice'):
            try:
                content_type = self.headers.get('Content-Type', '')
                if not content_type.startswith('multipart/form-data'):
                    self.send_response(400)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': 'Unsupported content type.'}).encode('utf-8'))
                    return

                form = FieldStorage(
                    fp=self.rfile,
                    headers=self.headers,
                    environ={'REQUEST_METHOD': 'POST', 'CONTENT_TYPE': content_type}
                )

                if 'file' not in form:
                    self.send_response(400)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': 'No PDF file field found.'}).encode('utf-8'))
                    return

                field = form['file']
                file_name = (form.getvalue('filename') or '').strip() or getattr(field, 'filename', '') or 'invoice.pdf'
                year = (form.getvalue('year') or str(__import__('datetime').datetime.now().year)).strip()
                target_dir = (INVOICE_ROOT / year).resolve()
                target_dir.mkdir(parents=True, exist_ok=True)
                target_path = (target_dir / file_name).resolve()

                if not str(target_path).startswith(str(INVOICE_ROOT.resolve())):
                    self.send_response(403)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': 'Invalid save location.'}).encode('utf-8'))
                    return

                data = field.file.read()
                if data is None or len(data) == 0:
                    self.send_response(400)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': 'Uploaded PDF is empty.'}).encode('utf-8'))
                    return

                target_path.write_bytes(data)
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({
                    'success': True,
                    'message': 'Invoice PDF saved successfully.',
                    'path': f'/Invoice/{year}/{file_name}'
                }).encode('utf-8'))
                return
            except Exception as exc:
                self.send_response(500)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({'success': False, 'message': f'Invoice PDF save failed: {exc}'}).encode('utf-8'))
                return

        if self.path == '/upload':
            try:
                content_type = self.headers.get('Content-Type', '')
                if not content_type.startswith('multipart/form-data'):
                    self.send_response(400)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': 'Unsupported content type.'}).encode('utf-8'))
                    return

                form = FieldStorage(
                    fp=self.rfile,
                    headers=self.headers,
                    environ={'REQUEST_METHOD': 'POST', 'CONTENT_TYPE': content_type}
                )

                if 'replacement' not in form:
                    self.send_response(400)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': 'No uploaded file field found.'}).encode('utf-8'))
                    return

                target_name = (form.getvalue('target') or '').strip()
                if not target_name:
                    self.send_response(400)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': 'No target filename provided.'}).encode('utf-8'))
                    return

                if target_name not in ALLOWED_FILE_TYPES:
                    self.send_response(403)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': 'This file target is not allowed on the server.'}).encode('utf-8'))
                    return

                field = form['replacement']
                uploaded_name = getattr(field, 'filename', '') or ''
                if not uploaded_name:
                    self.send_response(400)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': 'No file selected.'}).encode('utf-8'))
                    return

                target_path = (SITE_ROOT / target_name).resolve()
                allowed_types = ALLOWED_FILE_TYPES[target_name]
                uploaded_ext = Path(uploaded_name).suffix.lower()
                if uploaded_ext not in allowed_types:
                    allowed_desc = ', '.join(allowed_types)
                    self.send_response(400)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': f'Invalid file type for {target_name}. Expected: {allowed_desc}'}).encode('utf-8'))
                    return

                data = field.file.read()
                if data is None or len(data) == 0:
                    self.send_response(400)
                    self.send_header('Content-Type', 'application/json')
                    self.end_headers()
                    self.wfile.write(json.dumps({'success': False, 'message': 'Uploaded file is empty.'}).encode('utf-8'))
                    return

                target_path.write_bytes(data)
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({'success': True, 'message': f'File replaced: {target_name}', 'file': target_name}).encode('utf-8'))
                return
            except Exception as exc:
                self.send_response(500)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({'success': False, 'message': f'Upload failed: {exc}'}).encode('utf-8'))
                return

        if self.path == '/save-order':
            try:
                length = int(self.headers.get('Content-Length', '0'))
                raw = self.rfile.read(length)
                payload = json.loads(raw.decode('utf-8'))
                file_name = payload.get('fileName', 'order.xlsx')
                data = payload.get('data', [])

                if not file_name.endswith('.xlsx'):
                    file_name = f'{file_name}.xlsx'

                file_path = ORDER_DIR / file_name
                bytes_data = bytes(data)
                file_path.write_bytes(bytes_data)

                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({
                    'success': True,
                    'message': 'File saved successfully.',
                    'path': f'/orderfiles/{file_name}'
                }).encode('utf-8'))
                return
            except Exception as exc:
                self.send_response(500)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps({
                    'success': False,
                    'message': str(exc)
                }).encode('utf-8'))
                return

        file_path = self._safe_file_path(self.path)
        if file_path is not None:
            success, payload, status = self._handle_file_upload(file_path)
            if success:
                self.send_response(status)
                self.send_header('Content-Type', 'application/json')
                self.end_headers()
                self.wfile.write(json.dumps(payload).encode('utf-8'))
                return

            self.send_response(status)
            self.send_header('Content-Type', 'application/json')
            self.end_headers()
            self.wfile.write(json.dumps({'success': False, 'message': payload}).encode('utf-8'))
            return

        self.send_response(404)
        self.send_header('Content-Type', 'application/json')
        self.end_headers()
        self.wfile.write(json.dumps({'success': False, 'message': 'Not found'}).encode('utf-8'))

    def log_message(self, format, *args):
        return


if __name__ == '__main__':
    port = int(os.environ.get('PORT', '8000'))
    httpd = ThreadingHTTPServer(('0.0.0.0', port), Handler)
    print(f'Serving on http://0.0.0.0:{port}')
    httpd.serve_forever()
