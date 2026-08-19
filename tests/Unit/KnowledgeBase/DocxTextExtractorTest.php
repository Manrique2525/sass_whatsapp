<?php

declare(strict_types=1);

use App\Application\KnowledgeBase\Extractors\DocxTextExtractor;
use App\Domain\KnowledgeBase\Exceptions\DocumentExtractionFailedException;

/*
|--------------------------------------------------------------------------
| FASE 17 U2.3 — DOCX Extractor Unit Tests
|--------------------------------------------------------------------------
*/

function docxExt(): DocxTextExtractor
{
    return new DocxTextExtractor;
}

/**
 * Helper: build minimal valid DOCX (ZIP with word/document.xml).
 */
function buildDocxXml(string $bodyXml): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:body>'.$bodyXml.'</w:body>'
        .'</w:document>';

    return $xml;
}

function buildDocxFromXml(string $documentXml): string
{
    $tmpDir = sys_get_temp_dir().'/kb_docx_test_'.bin2hex(random_bytes(8));

    if (! is_dir($tmpDir)) {
        mkdir($tmpDir, 0755, true);
    }

    $docxPath = $tmpDir.'/test.docx';
    $wordDir = $tmpDir.'/word';

    if (! is_dir($wordDir)) {
        mkdir($wordDir, 0755, true);
    }

    file_put_contents($wordDir.'/document.xml', $documentXml);

    $zip = new ZipArchive;
    $opened = $zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    if ($opened !== true) {
        throw new RuntimeException("Failed to create test DOCX: {$opened}");
    }

    $zip->addFile($wordDir.'/document.xml', 'word/document.xml');
    $zip->close();

    $content = file_get_contents($docxPath);

    unlink($wordDir.'/document.xml');
    unlink($docxPath);
    rmdir($wordDir);
    rmdir($tmpDir);

    if ($content === false) {
        throw new RuntimeException('Failed to read generated DOCX');
    }

    return $content;
}

function buildDocxWithText(string ...$paragraphs): string
{
    $bodyXml = '';

    foreach ($paragraphs as $text) {
        $escaped = htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $bodyXml .= '<w:p><w:r><w:t>'.$escaped.'</w:t></w:r></w:p>';
    }

    return buildDocxFromXml(buildDocxXml($bodyXml));
}

/*
|--------------------------------------------------------------------------
| Tests
|--------------------------------------------------------------------------
*/

test('EXT-DOCX-01: single paragraph extracted', function (): void {
    $docx = buildDocxWithText('Hello world');
    $result = docxExt()->extract($docx);

    expect($result->text)->toContain('Hello world');
    expect($result->metadata['format'])->toBe('docx');
});

test('EXT-DOCX-02: multiple paragraphs separated by newlines', function (): void {
    $docx = buildDocxWithText('First paragraph', 'Second paragraph', 'Third');
    $result = docxExt()->extract($docx);

    expect($result->text)->toContain('First paragraph');
    expect($result->text)->toContain('Second paragraph');
    expect($result->text)->toContain('Third');
});

test('EXT-DOCX-03: Unicode preserved in DOCX', function (): void {
    $docx = buildDocxWithText('Español: ñ, á, é', '日本語テスト');
    $result = docxExt()->extract($docx);

    expect($result->text)->toContain('ñ');
    expect($result->text)->toContain('日本語');
});

test('EXT-DOCX-04: empty DOCX returns empty text', function (): void {
    $docx = buildDocxFromXml(buildDocxXml('<w:body></w:body>'));
    $result = docxExt()->extract($docx);

    expect($result->text)->toBe('');
    expect($result->characterCount)->toBe(0);
});

test('EXT-DOCX-05: invalid ZIP rejected', function (): void {
    $content = 'This is not a ZIP file';

    docxExt()->extract($content);
})->throws(DocumentExtractionFailedException::class, 'DOCX no es un ZIP válido');

test('EXT-DOCX-06: ZIP without word/document.xml rejected', function (): void {
    $tmpDir = sys_get_temp_dir().'/kb_docx_noxml_'.bin2hex(random_bytes(8));

    if (! is_dir($tmpDir)) {
        mkdir($tmpDir, 0755, true);
    }

    $docxPath = $tmpDir.'/test.docx';

    $zip = new ZipArchive;
    $zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('readme.txt', 'no document.xml here');
    $zip->close();

    $content = file_get_contents($docxPath);

    unlink($docxPath);
    rmdir($tmpDir);

    docxExt()->extract($content);
})->throws(DocumentExtractionFailedException::class, 'word/document.xml');

test('EXT-DOCX-07: ZIP bomb with high compression ratio rejected', function (): void {
    $junk = str_repeat('A', 1000 * 1000);

    $tmpDir = sys_get_temp_dir().'/kb_docx_bomb_'.bin2hex(random_bytes(8));

    if (! is_dir($tmpDir)) {
        mkdir($tmpDir, 0755, true);
    }

    $docxPath = $tmpDir.'/test.docx';

    $zip = new ZipArchive;
    $zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('junk.txt', $junk);
    $zip->close();

    $content = file_get_contents($docxPath);

    unlink($docxPath);
    rmdir($tmpDir);

    docxExt()->extract($content);
})->throws(DocumentExtractionFailedException::class);

test('EXT-DOCX-08: XML with nested paragraphs extracts cleanly', function (): void {
    $bodyXml = '<w:p><w:r><w:t>Title</w:t></w:r></w:p>'
        .'<w:p><w:r><w:t>Body text</w:t></w:r></w:p>';

    $docx = buildDocxFromXml(buildDocxXml($bodyXml));
    $result = docxExt()->extract($docx);

    expect($result->text)->toContain('Title');
    expect($result->text)->toContain('Body text');
});

test('EXT-DOCX-09: XML with whitespace-only text nodes skipped', function (): void {
    $bodyXml = '<w:p><w:r><w:t xml:space="preserve">  </w:t></w:r></w:p>'
        .'<w:p><w:r><w:t>Real text</w:t></w:r></w:p>';

    $docx = buildDocxFromXml(buildDocxXml($bodyXml));
    $result = docxExt()->extract($docx);

    expect($result->text)->toContain('Real text');
});

test('EXT-DOCX-10: malicious XML entities rejected', function (): void {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
        .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
        .'<w:body><w:p><w:r><w:t>&xxe;</w:t></w:r></w:p></w:body>'
        .'</w:document>';

    $docx = buildDocxFromXml($xml);

    docxExt()->extract($docx);
})->throws(DocumentExtractionFailedException::class);

test('EXT-DOCX-11: Zip Slip paths rejected', function (): void {
    $tmpDir = sys_get_temp_dir().'/kb_docx_slip_'.bin2hex(random_bytes(8));

    if (! is_dir($tmpDir)) {
        mkdir($tmpDir, 0755, true);
    }

    $docxPath = $tmpDir.'/test.docx';

    $zip = new ZipArchive;
    $zip->open($docxPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('../evil.txt', 'malicious');
    $zip->close();

    $content = file_get_contents($docxPath);

    unlink($docxPath);
    rmdir($tmpDir);

    docxExt()->extract($content);
})->throws(DocumentExtractionFailedException::class);

test('EXT-DOCX-12: special chars in paragraph text preserved', function (): void {
    $docx = buildDocxWithText('Line 1 with <special> & "chars"');
    $result = docxExt()->extract($docx);

    expect($result->characterCount)->toBeGreaterThan(0);
});
