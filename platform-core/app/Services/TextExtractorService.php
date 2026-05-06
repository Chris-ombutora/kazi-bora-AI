<?php

namespace App\Services;

use Smalot\PdfParser\Parser as PdfParser;
use PhpOffice\PhpWord\IOFactory as PhpWordIOFactory;
use Illuminate\Support\Facades\Log;

/**
 * Extracts raw text from uploaded CV files (PDF and DOCX).
 * Uses the same libraries as Developer 1's TextExtractor for consistency.
 */
class TextExtractorService
{
    /**
     * Extract text content from a file based on its extension.
     *
     * @param string $filePath Absolute path to the file
     * @return string Extracted raw text
     * @throws \Exception If file type is unsupported or extraction fails
     */
    public function extractText(string $filePath): string
    {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        try {
            return match ($ext) {
                'pdf' => $this->extractFromPdf($filePath),
                'docx' => $this->extractFromDocx($filePath),
                default => throw new \Exception("Unsupported file type: {$ext}"),
            };
        } catch (\Exception $e) {
            Log::error("Text extraction failed for {$filePath}: {$e->getMessage()}");
            throw $e;
        }
    }

    private function extractFromPdf(string $filePath): string
    {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($filePath);
        return $pdf->getText();
    }

    private function extractFromDocx(string $filePath): string
    {
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

        return trim($text);
    }
}
