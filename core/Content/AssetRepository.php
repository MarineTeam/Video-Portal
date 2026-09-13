<?php

declare(strict_types=1);

namespace Portal\Content;

use Portal\Db;
use Portal\Http\HttpException;

/**
 * Attachments, and the files behind them.
 *
 * The row and the file are written and deleted together, in that order and its
 * reverse. Getting the order wrong produces the two failure modes worth naming:
 * a row pointing at nothing, which downloads as a 404 an admin cannot explain,
 * and a file nothing points at, which is a disk that fills up with no way to
 * find out why.
 */
final class AssetRepository
{
    public function __construct(
        private readonly Db $db,
        private readonly string $storageRoot,
    ) {
    }

    // ------------------------------------------------------------------ reads

    /** @return list<array<string, mixed>> */
    public function forVideo(int $videoId): array
    {
        return $this->db->all(
            'SELECT * FROM {file_assets} WHERE video_id = ? ORDER BY position, id',
            [$videoId]
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->first('SELECT * FROM {file_assets} WHERE id = ?', [$id]);
    }

    /**
     * Every stored file, newest first, with what uses it — for the file manager.
     *
     * Until this existed the only way to find a file was to open the video it
     * was attached to, which is no way at all to answer "what is filling the
     * disk" or "where did that PDF go".
     *
     * CURSOR-PAGED on the id, not OFFSET: this is the screen somebody deletes
     * from, and an OFFSET page after a deletion skips the row that moved up into
     * the gap — exactly the file the person was about to look at next.
     *
     * The search matches the name the file was uploaded with and the title of
     * what it belongs to, with LIKE wildcards ESCAPED. Binding stops injection
     * but not `%` and `_`, which this codebase has already been bitten by once:
     * a search for "notes_v2" must not match "notesXv2".
     *
     * @return list<array<string, mixed>>
     */
    public function library(string $search = '', string $kind = '', int $beforeId = 0, int $limit = 50): array
    {
        $where = ['1 = 1'];
        $params = [];

        $search = trim($search);

        if ($search !== '') {
            $like = '%' . $this->db->escapeLike($search) . '%';
            $where[] = '(a.original_name LIKE ? OR v.title LIKE ? OR b.title LIKE ?)';
            array_push($params, $like, $like, $like);
        }

        $condition = FileKind::condition($kind);

        if ($condition !== null) {
            $where[] = $condition[0];
            $params = array_merge($params, $condition[1]);
        }

        if ($beforeId > 0) {
            $where[] = 'a.id < ?';
            $params[] = $beforeId;
        }

        return $this->db->all(
            'SELECT a.id, a.original_name, a.content_type, a.size_bytes, a.uploaded_by, a.created_at,
                    a.video_id, v.title AS video_title, v.slug AS video_slug,
                    b.id AS book_id, b.title AS book_title
               FROM {file_assets} a
               LEFT JOIN {videos} v ON v.id = a.video_id
               LEFT JOIN {books} b ON b.asset_id = a.id
              WHERE ' . implode(' AND ', $where) . '
              ORDER BY a.id DESC
              LIMIT ' . max(1, min(200, $limit)),
            $params
        );
    }

    /**
     * How many files, and how much space, in total.
     *
     * @return array{count: int, bytes: int}
     */
    public function totals(): array
    {
        $row = $this->db->first('SELECT COUNT(*) AS n, COALESCE(SUM(size_bytes), 0) AS bytes FROM {file_assets}');

        return ['count' => (int) ($row['n'] ?? 0), 'bytes' => (int) ($row['bytes'] ?? 0)];
    }

    /**
     * Delete a file from the file manager — unless a book is made of it.
     *
     * THE RULE. A book points at its file with ON DELETE SET NULL, so deleting
     * the file from a generic list does not fail: it succeeds, and leaves a
     * hymnal with no pages, every bookmark and highlight in it pointing at
     * nothing, and the reader showing an empty frame. So the file manager
     * refuses, and names the book — replacing or removing a book's file is done
     * on the book's own screen, where that consequence is in front of the person
     * doing it.
     *
     * Checked in the same request as the delete rather than trusted from the
     * page, which may be minutes old.
     *
     * @return string|null null when deleted; otherwise why not, naming the book
     */
    public function deleteUnlessInUse(int $id): ?string
    {
        $book = $this->db->first('SELECT id, title FROM {books} WHERE asset_id = ?', [$id]);

        if ($book !== null) {
            return sprintf(
                'is the file of the book "%s" — replace or remove it on that book\'s page',
                (string) $book['title']
            );
        }

        if ($this->find($id) === null) {
            return 'no longer exists';
        }

        $this->delete($id);

        return null;
    }

    /**
     * The absolute path of a stored file.
     *
     * Re-checked against the storage root rather than trusted. The value comes
     * from a column this code wrote, but a path assembled from a database
     * string and handed to readfile() is exactly the shape of bug that turns a
     * later, unrelated mistake into arbitrary file disclosure — and the check
     * costs one realpath.
     */
    public function absolutePath(string $relative): ?string
    {
        $root = realpath($this->storageRoot);

        if ($root === false) {
            return null;
        }

        $full = realpath($root . '/' . $relative);

        if ($full === false) {
            return null;
        }

        // Separator included, so "/storage-elsewhere" cannot pass as being
        // inside "/storage".
        return str_starts_with($full, $root . DIRECTORY_SEPARATOR) ? $full : null;
    }

    public function countFor(int $videoId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM {file_assets} WHERE video_id = ?',
            [$videoId]
        );
    }

    // ----------------------------------------------------------------- writes

    /**
     * Store an uploaded file and record it.
     *
     * The file lands first. A row written before the file exists is a download
     * that 404s with nothing to explain it; a file written before the row is at
     * worst an orphan, which costs disk and nothing else.
     *
     * @param  string $temporaryPath the upload's tmp_name, already verified
     * @return array<string, mixed> the stored row
     */
    public function store(
        ?int $videoId,
        string $temporaryPath,
        string $originalName,
        string $uploadedBy = ''
    ): array {
        $stored = AssetPolicy::storedName($originalName);

        if ($stored === null) {
            throw HttpException::badRequest('That kind of file cannot be attached.');
        }

        $size = (int) @filesize($temporaryPath);

        if ($size <= 0) {
            throw HttpException::badRequest('That file is empty.');
        }

        if ($size > AssetPolicy::MAX_BYTES) {
            throw HttpException::badRequest(sprintf(
                'That file is %s; the limit is %s.',
                AssetPolicy::formatSize($size),
                AssetPolicy::formatSize(AssetPolicy::MAX_BYTES)
            ));
        }

        $relative = AssetPolicy::relativePath($stored);
        $absolute = rtrim($this->storageRoot, '/\\') . '/' . $relative;

        $directory = dirname($absolute);

        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw HttpException::badRequest('The storage directory could not be created.');
        }

        /*
         * move_uploaded_file rather than rename: it refuses anything that did
         * not arrive as an upload, which is a check worth keeping even though
         * the caller has already done it. Two independent refusals beat one.
         */
        if (!@move_uploaded_file($temporaryPath, $absolute)
            && !@rename($temporaryPath, $absolute)) {
            throw HttpException::badRequest('That file could not be saved.');
        }

        $id = $this->db->insert('file_assets', [
            'video_id'      => $videoId,
            'path'          => $relative,
            'original_name' => AssetPolicy::displayName($originalName),
            'content_type'  => AssetPolicy::contentType($originalName),
            'size_bytes'    => $size,
            'uploaded_by'   => substr($uploadedBy, 0, 254),
            'position'      => $videoId === null ? 0 : $this->nextPosition($videoId),
            'created_at'    => date('Y-m-d H:i:s'),
        ]);

        $row = $this->find($id);

        if ($row === null) {
            throw new \RuntimeException('The attachment vanished immediately after being stored.');
        }

        return $row;
    }

    /**
     * Remove an attachment and its file.
     *
     * The row goes first. If the unlink then fails — a locked file on Windows,
     * a permission change — the result is an orphaned file rather than a row
     * pointing at nothing, and prune() can find it later. The other order
     * cannot be recovered from.
     */
    public function delete(int $id): void
    {
        $row = $this->find($id);

        if ($row === null) {
            return;
        }

        $this->db->execute('DELETE FROM {file_assets} WHERE id = ?', [$id]);

        $absolute = $this->absolutePath((string) $row['path']);

        if ($absolute !== null && is_file($absolute)) {
            @unlink($absolute);
        }
    }

    /**
     * Delete the files of rows that have already gone.
     *
     * Rows disappear on their own when a video is deleted, because of the
     * cascade — which is the right behaviour for the row and leaves the file
     * behind. Without this, deleting videos slowly fills the disk with files
     * nothing references and nothing can name.
     *
     * @return int how many files were removed
     */
    public function pruneOrphanedFiles(): int
    {
        $base = rtrim($this->storageRoot, '/\\') . '/assets';

        if (!is_dir($base)) {
            return 0;
        }

        $known = [];
        foreach ($this->db->all('SELECT path FROM {file_assets}') as $row) {
            $known[(string) $row['path']] = true;
        }

        $removed = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(rtrim($this->storageRoot, '/\\')) + 1));

            if (!isset($known[$relative]) && @unlink($file->getPathname())) {
                $removed++;
            }
        }

        return $removed;
    }

    private function nextPosition(int $videoId): int
    {
        $max = $this->db->value(
            'SELECT MAX(position) FROM {file_assets} WHERE video_id = ?',
            [$videoId]
        );

        return $max === null ? 0 : ((int) $max) + 1;
    }
}
