<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;

/**
 * Defence-in-depth for every user upload (Files, chat attachments, meeting
 * chat files):
 *
 *  1. Extension blocklist over EVERY dot-segment of the original name, so
 *     "invoice.exe.pdf" and "invoice.pdf.exe" are both rejected.
 *  2. Content sniffing (finfo on the actual bytes) — a renamed executable
 *     is caught even when its extension claims to be a document.
 *  3. Markup types (SVG / HTML) are blocked because they can carry scripts.
 *
 * Stored files use random hashed names outside the web root, and every
 * download is served with Content-Disposition: attachment + nosniff, so
 * nothing a user uploads can ever execute on the server or auto-run in
 * another user's browser. For public production deployments, adding a
 * ClamAV scan on top is the recommended final layer.
 */
class UploadGuard
{
    /** Executable / script / auto-run extensions. Merged with config. */
    protected const BLOCKED_EXTENSIONS = [
        'exe', 'msi', 'bat', 'cmd', 'com', 'scr', 'pif', 'cpl', 'msc', 'hta', 'application', 'gadget',
        'ps1', 'ps1xml', 'psc1', 'psm1', 'vb', 'vbs', 'vbe', 'ws', 'wsf', 'wsc', 'wsh',
        'sh', 'bash', 'zsh', 'csh', 'run', 'bin', 'elf', 'out', 'app', 'command',
        'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'phar', 'cgi', 'pl', 'py', 'rb',
        'js', 'jse', 'mjs', 'jar', 'jnlp', 'apk', 'appx', 'dmg', 'pkg', 'deb', 'rpm',
        'dll', 'sys', 'drv', 'ocx', 'reg', 'inf', 'lnk', 'url', 'iso', 'img', 'vhd',
        'svg', 'svgz', 'html', 'htm', 'xhtml', 'shtml', 'mht', 'mhtml', 'htaccess',
    ];

    /** MIME types (as sniffed from content) that must never be accepted. */
    protected const BLOCKED_MIMES = [
        'application/x-dosexec', 'application/x-msdownload', 'application/x-msdos-program',
        'application/vnd.microsoft.portable-executable', 'application/x-executable',
        'application/x-pie-executable', 'application/x-sharedlib', 'application/x-elf',
        'application/x-mach-binary', 'application/x-sh', 'application/x-shellscript',
        'text/x-shellscript', 'application/x-bat', 'application/x-msdos-batch',
        'application/x-php', 'text/x-php', 'application/x-httpd-php',
        'application/java-archive', 'application/x-java-archive',
        'application/vnd.android.package-archive',
        'text/html', 'application/xhtml+xml', 'image/svg+xml',
        'application/x-ms-shortcut', 'application/x-iso9660-image',
    ];

    /** Abort with 422 unless the upload is safe to store and re-serve. */
    public static function assertSafe(UploadedFile $file): void
    {
        $original = strtolower($file->getClientOriginalName());
        $blocked = array_unique(array_merge(
            self::BLOCKED_EXTENSIONS,
            array_map('strtolower', config('mypa.files.blocked_extensions', [])),
        ));

        // 1. Every dot-segment counts as an extension candidate.
        $segments = array_slice(explode('.', $original), 1);
        foreach ($segments as $segment) {
            if (in_array(trim($segment), $blocked, true)) {
                abort(422, "Files of type .{$segment} are not allowed for safety reasons.");
            }
        }

        // 2. Sniff the real content — renaming an .exe to .pdf does not help.
        $sniffed = strtolower((string) $file->getMimeType());
        if (in_array($sniffed, self::BLOCKED_MIMES, true)) {
            abort(422, 'This file looks like a program or script and cannot be shared for safety reasons.');
        }
    }
}
