<?php
namespace App;

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;

class TextExtractor {
    public function extractText(string $filePath): string {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        
        try {
            if ($ext === 'pdf') {
                return $this->extractFromPdf($filePath);
            } elseif ($ext === 'docx') {
                return $this->extractFromDocx($filePath);
            } else {
                throw new \Exception("Unsupported file type: " . $ext);
            }
        } catch (\Exception $e) {
            error_log("Extraction failed for $filePath: " . $e->getMessage());
            throw $e;
        }
    }

    private function extractFromPdf(string $filePath): string {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($filePath);
        return $pdf->getText();
    }

    private function extractFromDocx(string $filePath): string {
        $phpWord = PhpWordIOFactory::load($filePath, 'Word2007');
        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . " ";
                } elseif (method_exists($element, 'getElements')) {
                    foreach ($element->getElements() as $childElement) {
                        if (method_exists($childElement, 'getText')) {
                            $text .= $childElement->getText() . " ";
                        }
                    }
                }
            }
        }
        return $text;
    }
}
