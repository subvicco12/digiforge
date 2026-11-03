"""Synthetic local test input factory, not a production authoring endpoint."""
import sys
from pathlib import Path
from test_external_document_illustrated import illustrated,pdf
if __name__=='__main__':
    if len(sys.argv)!=3:raise SystemExit(2)
    illustrated(Path(sys.argv[1]));pdf(Path(sys.argv[2]),header='OUR CELEBRATION')
