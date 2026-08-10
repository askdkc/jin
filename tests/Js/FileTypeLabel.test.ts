import assert from 'node:assert/strict';
import test from 'node:test';
import { fileTypeLabelKey } from '../../resources/js/lib/file-type-label.ts';

test('uses readable labels for Office and PDF files', () => {
    assert.equal(
        fileTypeLabelKey(
            'report.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ),
        'Word document',
    );
    assert.equal(fileTypeLabelKey('budget.xlsx', null), 'Spreadsheet');
    assert.equal(fileTypeLabelKey('roadmap.PPTX', null), 'Presentation');
    assert.equal(fileTypeLabelKey('contract.pdf', null), 'PDF document');
});

test('falls back to normalized MIME types when the extension is unknown', () => {
    assert.equal(
        fileTypeLabelKey('preview', 'image/png; charset=binary'),
        'Image',
    );
    assert.equal(fileTypeLabelKey('recording', 'video/mp4'), 'Video');
    assert.equal(fileTypeLabelKey('notes', 'text/plain'), 'Text file');
    assert.equal(fileTypeLabelKey('download', null), 'File');
});
