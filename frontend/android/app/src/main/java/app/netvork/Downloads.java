package app.netvork;

import android.app.Activity;
import android.app.DownloadManager;
import android.net.Uri;
import android.os.Build;
import android.os.Environment;
import android.webkit.CookieManager;
import android.webkit.URLUtil;
import android.webkit.WebView;
import android.widget.Toast;

/**
 * Saving a file, which a WebView does not do on its own.
 *
 * A browser has downloading built in. A WebView has nothing: no download UI,
 * no destination, not even a default - it simply drops a response it is asked
 * to save, silently and with no error anywhere. So the web app's ordinary
 * download, which builds the file in memory and clicks a link at it, did
 * nothing at all inside the app. Tapping a file somebody had sent you looked
 * broken because it was.
 *
 * Capacitor does not fill this gap either: it has no download listener, and
 * its own URL handling deliberately keeps blob: URLs and our own host inside
 * the WebView. Nothing was ever going to reach the system.
 *
 * So the file is handed to Android's DownloadManager, which is what a browser
 * does with it too - it downloads in the background, survives the app being
 * closed, shows progress in the notification shade, and leaves something the
 * person can open from Downloads afterwards. The URL it is given carries a
 * signature rather than our auth header, because DownloadManager is another
 * process entirely and takes none of our headers with it.
 */
final class Downloads {

    private Downloads() {}

    /** Hand anything the WebView wanted to save to the system downloader. */
    static void install(Activity activity, WebView webView) {
        if (webView == null) {
            return;
        }

        webView.setDownloadListener((url, userAgent, contentDisposition, mimeType, contentLength) -> {
            /*
             * Only our own files, and only over https.
             *
             * A download listener fires for whatever the page asks to save,
             * so this is the one place where a page could reach the system
             * downloader. It is held to the site the shell exists to show.
             */
            Uri uri;
            try {
                uri = Uri.parse(url);
            } catch (Exception e) {
                return;
            }
            String host = uri.getHost();
            if (!"https".equals(uri.getScheme()) || host == null
                    || !(host.equals("netvork.app") || host.endsWith(".netvork.app"))) {
                return;
            }

            try {
                String name = URLUtil.guessFileName(url, contentDisposition, mimeType);

                DownloadManager.Request request = new DownloadManager.Request(uri);
                request.setTitle(name);
                request.setDescription("Netvork");
                request.setMimeType(mimeType);
                // Progress while it runs and a tap to open when it finishes,
                // which is the whole of what people expect a download to do.
                request.setNotificationVisibility(DownloadManager.Request.VISIBILITY_VISIBLE_NOTIFY_COMPLETED);
                if (userAgent != null) {
                    request.addRequestHeader("User-Agent", userAgent);
                }

                String cookies = CookieManager.getInstance().getCookie(url);
                if (cookies != null) {
                    request.addRequestHeader("Cookie", cookies);
                }

                /*
                 * The public Downloads folder, where a person expects to find
                 * what they downloaded - but only where writing there is free.
                 * Below Android 10 it needs a storage permission, and asking
                 * for the whole of someone's storage to save one PDF is a poor
                 * trade; those versions get the app's own external folder,
                 * which needs no permission and which DownloadManager still
                 * opens from its notification.
                 */
                if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
                    request.setDestinationInExternalPublicDir(Environment.DIRECTORY_DOWNLOADS, name);
                } else {
                    request.setDestinationInExternalFilesDir(activity, Environment.DIRECTORY_DOWNLOADS, name);
                }

                DownloadManager manager = activity.getSystemService(DownloadManager.class);
                if (manager == null) {
                    return;
                }
                manager.enqueue(request);

                // Said out loud, because the download itself is silent until
                // the notification appears and a tap with no feedback at all
                // is what this whole class is here to fix.
                activity.runOnUiThread(() ->
                    Toast.makeText(activity, "Downloading " + name, Toast.LENGTH_SHORT).show());
            } catch (Exception e) {
                activity.runOnUiThread(() ->
                    Toast.makeText(activity, "Could not download that file.", Toast.LENGTH_SHORT).show());
            }
        });
    }
}
