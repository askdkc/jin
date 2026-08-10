<?php

use App\Support\StoredFileContentSanitizer;

test('embedded image data is removed while meaningful alternative text remains', function () {
    $content = <<<'MARKDOWN'
        Before ![](data:image/jpeg;base64,/9j/4AAQSkZJRgABAQ) middle ![Revenue chart](data:image/png;base64,iVBORw0KGgo=) after.
        MARKDOWN;

    expect((new StoredFileContentSanitizer)->sanitize($content))
        ->toBe('Before   middle  Revenue chart  after.');
});

test('regular remote images remain unchanged', function () {
    $content = 'Before ![Project map](https://example.com/map.png) after.';

    expect((new StoredFileContentSanitizer)->sanitize($content))->toBe($content);
});

test('truncated embedded image data is removed through the end of the content', function () {
    $content = 'Visible text ![](data:image/jpeg;base64,/9j/4AAQ';

    expect((new StoredFileContentSanitizer)->sanitize($content))->toBe('Visible text  ');
});
