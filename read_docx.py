import sys
try:
    from docx import Document
except ImportError:
    print("python-docx not installed")
    sys.exit(1)

def main():
    path = "c:\\Users\\Chris\\Desktop\\kazibora AI\\KaziBora_Market_Analysis (2).docx"
    doc = Document(path)
    for i, para in enumerate(doc.paragraphs):
        text = para.text.strip()
        if text:
            print(f"[{i}] {text}")

if __name__ == '__main__':
    main()
