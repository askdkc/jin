import assert from 'node:assert/strict';
import test from 'node:test';
import { nextFileThumbnailSource } from '../../resources/js/lib/file-thumbnail.ts';

test('ready generated previews are preferred over image originals', () => {
    assert.deepEqual(
        nextFileThumbnailSource({
            mimeType: 'image/png',
            thumbnailUrl: '/files/1/thumbnail',
            originalPreviewUrl: '/files/1/preview',
        }),
        {
            kind: 'generated',
            url: '/files/1/thumbnail',
        },
    );
});

test('raster images use the authenticated original preview', () => {
    assert.deepEqual(
        nextFileThumbnailSource({
            mimeType: 'image/jpeg',
            thumbnailUrl: null,
            originalPreviewUrl: '/files/2/preview',
        }),
        {
            kind: 'original',
            url: '/files/2/preview',
        },
    );
});

test('failed generated image previews fall back to the original', () => {
    assert.deepEqual(
        nextFileThumbnailSource({
            mimeType: 'image/webp',
            thumbnailUrl: '/files/3/thumbnail',
            originalPreviewUrl: '/files/3/preview',
            failedUrls: ['/files/3/thumbnail'],
        }),
        {
            kind: 'original',
            url: '/files/3/preview',
        },
    );
});

test('failed PDF previews and unsupported files have no thumbnail source', () => {
    assert.equal(
        nextFileThumbnailSource({
            mimeType: 'application/pdf',
            thumbnailUrl: '/files/4/thumbnail',
            originalPreviewUrl: '/files/4/preview',
            failedUrls: ['/files/4/thumbnail'],
        }),
        null,
    );
    assert.equal(
        nextFileThumbnailSource({
            mimeType:
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            thumbnailUrl: null,
            originalPreviewUrl: '/files/5/preview',
        }),
        null,
    );
    assert.equal(
        nextFileThumbnailSource({
            mimeType: null,
            thumbnailUrl: null,
            originalPreviewUrl: null,
        }),
        null,
    );
});
