package app.netvork;

import android.app.Notification;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.media.AudioAttributes;
import android.net.Uri;
import android.os.Build;
import androidx.annotation.NonNull;
import androidx.core.app.NotificationCompat;
import com.google.firebase.messaging.RemoteMessage;

import java.util.Map;

/**
 * The native half of ringing.
 *
 * Calls arrive as data-only FCM messages, and Android wakes this service for
 * those even when the app process is dead — which is the entire point. A
 * notification drawn by FCM itself (the "notification block" path every other
 * kind of push here uses) is reliable but plain: one big tappable surface
 * that answers the call, which is how "I only wanted to silence it" came to
 * mean "I picked it up". Building the notification in code buys the three
 * things that make it behave like a phone:
 *
 *   Answer and Decline as separate buttons, with the body tap doing neither —
 *   it just opens the calls screen, where the ongoing-call banner offers the
 *   same choice at leisure.
 *
 *   FLAG_INSISTENT, which loops the channel's ringtone until the notification
 *   is dismissed or times out, instead of playing it once.
 *
 *   A full-screen intent, so on a locked phone the call can take the screen
 *   the way a phone call does. (Newer Android reserves this for apps the user
 *   has blessed; where it is refused, the heads-up notification still shows.)
 *
 * Everything not a call is handed straight to Capacitor's own service, so the
 * plugin's behaviour — token rotation included, via the inherited
 * onNewToken — is exactly what it was.
 */
public class NetvorkMessagingService extends com.capacitorjs.plugins.pushnotifications.MessagingService {

    /** Must match what the web bundle creates and the server addresses. */
    private static final String CALL_CHANNEL = "calls2";

    @Override
    public void onMessageReceived(@NonNull RemoteMessage remoteMessage) {
        Map<String, String> data = remoteMessage.getData();

        /*
         * The call is over — clear the ring rather than drawing anything.
         *
         * This has to be handled before everything below, and it is the
         * message that was missing entirely: the websocket 'end' signal only
         * reaches an app that is running, and the whole reason this
         * notification exists is that the app is not. So when the caller
         * hung up, nothing here ever heard about it and the notification
         * stayed — ringing, because it carries FLAG_INSISTENT, until the
         * 45-second timeout expired on its own.
         *
         * The id is derived from the call uuid exactly as it was when the
         * ring was posted, which is what lets this cancel that one.
         */
        if ("call_cancel".equals(data.get("kind"))) {
            String cancelUuid = data.get("call_uuid");
            NotificationManager manager = getSystemService(NotificationManager.class);
            if (manager != null && cancelUuid != null) {
                manager.cancel(cancelUuid.hashCode());
            }
            return;
        }

        if (!"call".equals(data.get("kind"))) {
            /*
             * Belt and braces with MainActivity, and not redundant.
             *
             * Android can start this service for a push without the activity
             * ever having run in this process — after a reboot, or once the
             * app has been swiped away. Capacitor's own handler below posts
             * straight to the channel the server named, and if that channel
             * does not exist yet the notification is quietly downgraded to
             * the fallback channel: no custom sound, no heads-up. Creating
             * an existing channel costs nothing and changes nothing.
             */
            NotificationChannels.ensure(this);
            super.onMessageReceived(remoteMessage);
            return;
        }

        // On screen: the websocket is live and the in-app UI is already
        // ringing. A system notification on top would ring twice.
        if (MainActivity.inForeground) {
            return;
        }

        String callUuid = data.get("call_uuid");
        int id = callUuid != null ? callUuid.hashCode() : 1;

        ensureChannel();

        NotificationCompat.Builder builder = new NotificationCompat.Builder(this, CALL_CHANNEL)
            .setSmallIcon(getApplicationInfo().icon)
            .setContentTitle(orDefault(data.get("title"), "Netvork"))
            .setContentText(orDefault(data.get("body"), "Incoming call"))
            .setCategory(NotificationCompat.CATEGORY_CALL)
            .setPriority(NotificationCompat.PRIORITY_MAX)
            .setVisibility(NotificationCompat.VISIBILITY_PUBLIC)
            // The ring is over well before this; a stale ringing notification
            // for a call nobody can answer any more is worse than none.
            .setTimeoutAfter(45_000)
            .setAutoCancel(true);

        // Body tap: look at the call, decide there. Deliberately NOT answer.
        builder.setContentIntent(openApp(id, "/calls", id));

        String joinUrl = data.get("url");
        if (joinUrl != null) {
            builder.addAction(0, "Answer", openApp(id + 1, joinUrl, id));
        }

        String declineUrl = data.get("decline_url");
        if (declineUrl != null) {
            Intent decline = new Intent(this, DeclineReceiver.class)
                .putExtra(DeclineReceiver.EXTRA_URL, declineUrl)
                .putExtra(DeclineReceiver.EXTRA_NOTIFICATION_ID, id);
            builder.addAction(0, "Decline", PendingIntent.getBroadcast(
                this, id + 2, decline,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE));
        }

        // A locked phone gets the call over the lock screen, where the
        // platform allows it.
        builder.setFullScreenIntent(openApp(id + 3, "/calls", id), true);

        Notification notification = builder.build();
        // Loop the ringtone until answered, declined, dismissed or timed out —
        // the difference between a ring and a chirp with ambitions.
        notification.flags |= Notification.FLAG_INSISTENT;

        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager != null) {
            manager.notify(id, notification);
        }
    }

    /**
     * An intent into the webview at a path, cold start included.
     *
     * It carries the notification id because an action button's tap does not
     * auto-cancel the way a body tap does — Answer opened the call with the
     * notification still up and, thanks to FLAG_INSISTENT, still ringing over
     * the conversation it had just started. The sanctioned route since the
     * trampoline ban is for the opened activity itself to clear it, so
     * MainActivity cancels whatever id its intent names.
     */
    private PendingIntent openApp(int requestCode, String path, int notificationId) {
        Intent intent = new Intent(Intent.ACTION_VIEW,
            Uri.parse("https://netvork.app" + path), this, MainActivity.class);
        intent.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK | Intent.FLAG_ACTIVITY_SINGLE_TOP);
        intent.putExtra(MainActivity.EXTRA_CANCEL_NOTIFICATION, notificationId);

        return PendingIntent.getActivity(this, requestCode, intent,
            PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
    }

    /**
     * The channel the web bundle normally creates, recreated here if missing.
     *
     * Belt and braces for one cold-start ordering: a call arriving after
     * install but before the app was ever opened would otherwise address a
     * channel that does not exist, and Android drops those silently.
     */
    private void ensureChannel() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (manager == null || manager.getNotificationChannel(CALL_CHANNEL) != null) {
            return;
        }

        NotificationChannel channel = new NotificationChannel(
            CALL_CHANNEL, "Incoming calls", NotificationManager.IMPORTANCE_HIGH);
        channel.setDescription("Rings when somebody calls you");
        channel.enableVibration(true);
        channel.setSound(
            Uri.parse("android.resource://" + getPackageName() + "/raw/ringtone"),
            new AudioAttributes.Builder()
                .setUsage(AudioAttributes.USAGE_NOTIFICATION_RINGTONE)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build());
        manager.createNotificationChannel(channel);
    }

    private static String orDefault(String value, String fallback) {
        return value == null || value.isEmpty() ? fallback : value;
    }
}
