<?php

declare(strict_types=1);

namespace Portal\Admin;

use Portal\Content\AssetPolicy;
use Portal\Content\FileKind;

/**
 * Every stored file, what it belongs to, and how much room it takes.
 */
final class AdminFileView
{
    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $token = e((string) $data['token']);
        $totals = (array) ($data['totals'] ?? []);
        $search = (string) ($data['search'] ?? '');
        $kind = (string) ($data['kind'] ?? '');

        $size = e(AssetPolicy::formatSize((int) ($totals['bytes'] ?? 0)));
        $count = (int) ($totals['count'] ?? 0);

        $body = <<<HTML
        <h1>Files</h1>

        <p class="muted">Every file uploaded to this site — attachments on videos and the files books
           are made of. <strong>{$count}</strong> in all, taking <strong>{$size}</strong>. Files are
           added on the video or book they belong to; this screen is for finding them and clearing
           them out.</p>

        {$this->filters($data, $search, $kind)}
        {$this->table($data, $token)}
        HTML;

        return (new AdminView())->shell($body, $data);
    }

    /** @param array<string, mixed> $data */
    private function filters(array $data, string $search, string $kind): string
    {
        $options = '<option value="">Every kind</option>';

        foreach ((array) ($data['kinds'] ?? []) as $value => $label) {
            $options .= sprintf(
                '<option value="%s"%s>%s</option>',
                e((string) $value),
                $value === $kind ? ' selected' : '',
                e((string) $label)
            );
        }

        return sprintf(
            '<form method="get" action="/admin/files" class="toolbar">
               <input type="search" name="q" value="%s" placeholder="File name, video or book">
               <select name="kind">%s</select>
               <button class="btn secondary">Find</button>
             </form>',
            e($search),
            $options
        );
    }

    /** @param array<string, mixed> $data */
    private function table(array $data, string $token): string
    {
        $files = (array) ($data['files'] ?? []);

        if ($files === []) {
            return '<p class="muted">No files match.</p>';
        }

        $rows = '';

        foreach ($files as $file) {
            $id = (int) $file['id'];

            /*
             * What it belongs to, and a book's file is marked AND cannot be
             * ticked. The repository refuses it anyway — this is so nobody
             * selects forty files, presses delete, and learns about the hymnal
             * from an error. The server-side refusal is the rule; the disabled
             * box is courtesy.
             */
            if ($file['book_id'] !== null) {
                $belongs = sprintf(
                    'Book: <a href="/admin/books/%d">%s</a> <span class="pill">in use</span>',
                    (int) $file['book_id'],
                    e((string) $file['book_title'])
                );
                $box = '<input type="checkbox" disabled title="Replace or remove this on the book\'s page">';
            } else {
                $belongs = $file['video_id'] !== null
                    ? sprintf(
                        'Video: <a href="/admin/videos/%d">%s</a>',
                        (int) $file['video_id'],
                        e((string) ($file['video_title'] ?? 'untitled'))
                    )
                    : '<span class="muted">Nothing — safe to clear</span>';
                $box = sprintf('<input type="checkbox" name="files[]" value="%d">', $id);
            }

            $rows .= sprintf(
                '<tr>
                   <td>%s</td>
                   <td><strong>%s</strong><div class="muted small">%s · %s</div></td>
                   <td class="small">%s</td>
                   <td class="muted small">%s<br>%s</td>
                 </tr>',
                $box,
                e((string) $file['original_name']),
                e(FileKind::labels()[FileKind::of((string) $file['content_type'])] ?? 'File'),
                e(AssetPolicy::formatSize((int) $file['size_bytes'])),
                $belongs,
                e((string) $file['created_at']),
                e((string) ($file['uploaded_by'] ?? ''))
            );
        }

        $next = (int) ($data['nextBefore'] ?? 0) > 0
            ? sprintf(
                '<p><a class="btn secondary" href="/admin/files?%s">Older files</a></p>',
                e(http_build_query(array_filter([
                    'q'      => $data['search'] ?? '',
                    'kind'   => $data['kind'] ?? '',
                    'before' => (int) $data['nextBefore'],
                ])))
            )
            : '';

        return sprintf(
            '<form method="post" action="/admin/files">
               <input type="hidden" name="_token" value="%s">
               <table>
                 <thead><tr><th></th><th>File</th><th>Belongs to</th><th>Added</th></tr></thead>
                 <tbody>%s</tbody>
               </table>
               <button class="btn danger" onclick="return confirm(\'Delete the ticked files? This removes them from disk.\')">
                 Delete ticked files
               </button>
             </form>
             %s',
            $token,
            $rows,
            $next
        );
    }
}
