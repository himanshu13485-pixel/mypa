package app.netvork;

import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.content.Context;
import android.media.AudioAttributes;
import android.net.Uri;
import android.os.Build;

/**
 * The categories this app is allowed to interrupt somebody in.
 *
 * Until every action in the app started pushing, there was exactly one kind
 * of alert worth naming — a call — and everything else was posted to a
 * channel id ("default") that no code here ever created. Android's answer to
 * a notification naming a channel that does not exist is to fall back to the
 * FCM library's own "Miscellaneous" channel, so a chat message, a bill
 * falling due and an administrator suspending your account arrived at the
 * same importance with the same tone and one useless row in Android's
 * notification settings to control all three.
 *
 * A channel is the only handle Android gives a person for turning one sort of
 * alert down without turning the app off, so the channels are the feature
 * here as much as the pushes are. Five of them, chosen so that each is a
 * decision somebody might genuinely make differently: chat, time-bound
 * reminders, money, other people's activity, and the account itself.
 *
 * The ids carry a version for a reason worth remembering: a channel's
 * importance, sound and vibration are fixed at creation and CANNOT be changed
 * by a later app update — from then on they belong to the user, which is the
 * point of them. Shipping different defaults means shipping a new id, and a
 * new id means a fresh row in settings that has forgotten whatever the person
 * had chosen. So the version is bumped only when the defaults are actually
 * wrong, never to tidy anything up.
 */
public final class NotificationChannels {

    /** Must match the ids in App\Support\Alerts on the server. */
    public static final String MESSAGES = "messages_v1";
    public static final String REMINDERS = "reminders_v1";
    public static final String MONEY = "money_v1";
    public static final String SOCIAL = "social_v1";
    public static final String SYSTEM = "system_v1";

    private NotificationChannels() {
    }

    /**
     * Create any channel that does not exist yet.
     *
     * Cheap and idempotent — createNotificationChannel on an existing id is a
     * no-op that preserves the user's own settings — so this is safe to call
     * on every launch and on every push, which is what it takes to be sure a
     * channel exists before something is posted to it.
     */
    public static void ensure(Context context) {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) {
            return;
        }
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        if (manager == null) {
            return;
        }

        // Chat. High, because a message you are waiting on is worth a
        // heads-up, and the short double-buzz of a messenger.
        create(context, manager, MESSAGES, "Messages",
            "Chat messages and missed calls",
            NotificationManager.IMPORTANCE_HIGH, "notify_message",
            new long[] { 0, 180, 90, 180 });

        // Anything with a time attached. High for the same reason the server
        // sends these at high urgency: a reminder delivered late is not a late
        // reminder, it is a wrong one.
        create(context, manager, REMINDERS, "Reminders",
            "Tasks, habits, goals, meetings and events that are due",
            NotificationManager.IMPORTANCE_HIGH, "notify_reminder",
            new long[] { 0, 250, 120, 250 });

        // Money. Separate from reminders because the cost of missing one is
        // different in kind, and because it is the category people most often
        // want louder than everything else.
        create(context, manager, MONEY, "Bills & payments",
            "Bills falling due, payments, plans and shared expense ledgers",
            NotificationManager.IMPORTANCE_HIGH, "notify_money",
            new long[] { 0, 400, 150, 200 });

        // Somebody did something involving you. Default importance: it makes
        // a sound and waits in the drawer, rather than taking over the screen.
        create(context, manager, SOCIAL, "Activity",
            "Shares, invites, assignments and connection requests",
            NotificationManager.IMPORTANCE_DEFAULT, "notify_social",
            new long[] { 0, 200, 100, 200 });

        // The app or an administrator acting on your account. Rare, and
        // nothing here is ever time-critical enough to interrupt for.
        create(context, manager, SYSTEM, "Account",
            "Account changes, moderation and administrative decisions",
            NotificationManager.IMPORTANCE_DEFAULT, "notify_social",
            new long[] { 0, 300 });
    }

    private static void create(
        Context context,
        NotificationManager manager,
        String id,
        String name,
        String description,
        int importance,
        String rawSound,
        long[] vibration
    ) {
        if (manager.getNotificationChannel(id) != null) {
            return;
        }

        NotificationChannel channel = new NotificationChannel(id, name, importance);
        channel.setDescription(description);
        channel.enableVibration(true);
        channel.setVibrationPattern(vibration);
        // Grouped in the drawer by channel, so twenty ledger entries collapse
        // into one expandable stack instead of twenty rows.
        channel.setShowBadge(true);
        channel.setSound(
            Uri.parse("android.resource://" + context.getPackageName() + "/raw/" + rawSound),
            new AudioAttributes.Builder()
                // NOTIFICATION, not RINGTONE: these follow the notification
                // volume slider and respect Do Not Disturb, which a ringtone
                // usage is allowed to ignore. Only the call channel gets to
                // behave like a phone.
                .setUsage(AudioAttributes.USAGE_NOTIFICATION)
                .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                .build());

        manager.createNotificationChannel(channel);
    }
}
