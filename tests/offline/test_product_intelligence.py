import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'bin/product-intelligence.py'

class ProductIntelligenceImportTest(unittest.TestCase):
    def load(self):
        spec = importlib.util.spec_from_file_location('product_intelligence', SCRIPT)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        return module

    def test_import_retains_bytes_identity_null_and_hash(self):
        m = self.load()
        with tempfile.TemporaryDirectory() as tmp:
            p = Path(tmp) / 'concepts.csv'
            p.write_text('V2 ID,Source DG ID,Physical Product,Price USD\nDG2-001,DG-001,PP-001,\n', encoding='utf-8')
            manifest = {'sources': [{'id': 'drive-id', 'name': p.name, 'path': str(p), 'modified_at': '2026-11-02T17:00:00Z'}], 'sections': {'concepts': {'source_id': 'drive-id', 'sheet': 'concepts'}}}
            packet = m.convert(manifest)
            row = packet['sections']['concepts']['rows'][0]
            self.assertEqual('DG-001', row['Source DG ID'])
            self.assertEqual('2', row['_source_row'])
            self.assertIsNone(row['Price USD'])
            self.assertEqual(m.rows_hash([row]), packet['sections']['concepts']['rows_sha256'])
            self.assertFalse(packet['production_authority'])

    def test_rejects_mismatched_source_hash(self):
        m = self.load()
        with tempfile.TemporaryDirectory() as tmp:
            p = Path(tmp)/'x.csv';p.write_text('A\nx\n')
            with self.assertRaises(ValueError):
                m.convert({'sources':[{'id':'x','name':'x.csv','path':str(p),'modified_at':'2026-11-02T00:00:00Z','sha256':'a'*64}], 'sections':{'concepts':{'source_id':'x','sheet':'x'}}})

    def test_csv_duplicate_headers_rejected(self):
        m = self.load()
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/'x.csv';p.write_text('A,A\nx,y\n')
            with self.assertRaises(ValueError): m.read_csv(p)

    def test_xlsx_empty_inline_string_remains_missing(self):
        m=self.load()
        import zipfile
        with tempfile.TemporaryDirectory() as tmp:
            p=Path(tmp)/'empty.xlsx'
            m.export_xlsx({'sections':{'concepts':{'rows':[{'A':'one','B':None}]}}},p)
            # openpyxl-produced evidence encodes blank inline cells without <is>.
            with zipfile.ZipFile(p) as z: entries={n:z.read(n) for n in z.namelist()}
            entries['xl/worksheets/sheet1.xml']=entries['xl/worksheets/sheet1.xml'].replace(b'<c t="inlineStr"><is><t xml:space="preserve"></t></is></c>',b'<c r="B2" t="inlineStr"/>')
            # Export assigns cell coordinates; headers/data are otherwise unchanged.
            with zipfile.ZipFile(p,'w') as z:
                for n,v in entries.items(): z.writestr(n,v)
            self.assertIsNone(m.read_xlsx(p,'concepts')[0]['B'])

    def test_xlsx_export_uses_literal_strings(self):
        m=self.load()
        import zipfile
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'out.xlsx'
            m.export_xlsx({'sections':{'concepts':{'rows':[{'Concept':'=1+1','Cost':None}]}}},path)
            with zipfile.ZipFile(path) as z:
                sheet=z.read('xl/worksheets/sheet1.xml').decode()
                self.assertNotIn('<f>',sheet)
                self.assertIn('=1+1',sheet)

if __name__ == '__main__': unittest.main()
