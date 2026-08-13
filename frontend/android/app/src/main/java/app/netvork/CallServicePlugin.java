package app.netvork;

import android.content.Context;
import android.content.Intent;
import android.media.AudioManager;
import android.os.Build;

import com.getcapacitor.Plugin;
import com.getcapacitor.PluginCall;
import com.getcapacitor.PluginMethod;
import com.getcapacitor.annotation.CapacitorPlugin;

/**
 * Lets the web app start and stop the call foreground service.
 *
 * The web app is the only thing that knows whether a call is running, so it
 * owns the decision; this is the doorway. Both methods are safe to call more
 * than once — starting a running service just refreshes its notification, and
 * stopping a stopped one does nothing — because the web app calls them from
 * effects, and an effect that must fire exactly once is a bug waiting.
 */
@CapacitorPlugin(name = "CallService")
public class CallServicePlugin extends Plugin {

    @PluginMethod
    public void start(PluginCall call) {
        Intent intent = new Intent(getContext(), CallForegroundService.class);
        intent.putExtra(CallForegroundService.EXTRA_LABEL, call.getString("label"));
        try {
            if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.O) {
                getContext().startForegroundService(intent);
            } else {
                getContext().startService(intent);
            }
            call.resolve();
        } catch (Exception e) {
            /*
             * Android 12+ refuses a foreground service started from the
             * background, and Android 14 is stricter again. Rejecting rather
             * than throwing keeps a refusal from taking the call down with it:
             * without the service the microphone is lost on backgrounding,
             * which is worse than it was but is not worse than no call.
             */
            call.reject("could not start the call service: " + e.getMessage());
        }
    }

    /**
     * Earpiece or loudspeaker, which on Android only native code can decide.
     *
     * setSinkId — the web API the app uses on a desktop to pick an output —
     * is not implemented in Chrome on Android at all, and enumerateDevices
     * lists no audio outputs there either. So the careful label-matching that
     * chooses an earpiece for calls and a loudspeaker for meetings had nothing
     * to match on and silently changed nothing: every call came out of the
     * loudspeaker because that is the WebView's default.
     *
     * AudioManager is the real switch. MODE_IN_COMMUNICATION is what tells
     * Android this is a voice call rather than media playback — without it
     * the routing choice below is ignored, and the volume keys would go on
     * adjusting the ringtone instead of the call.
     */
    @PluginMethod
    public void setSpeakerphone(PluginCall call) {
        boolean on = Boolean.TRUE.equals(call.getBoolean("on", false));
        try {
            AudioManager audio = (AudioManager) getContext().getSystemService(Context.AUDIO_SERVICE);
            if (audio != null) {
                audio.setMode(AudioManager.MODE_IN_COMMUNICATION);
                audio.setSpeakerphoneOn(on);
            }
            call.resolve();
        } catch (Exception e) {
            call.reject("could not route audio: " + e.getMessage());
        }
    }

    /** Hands the routing back to the system when the call is over. */
    @PluginMethod
    public void resetAudio(PluginCall call) {
        try {
            AudioManager audio = (AudioManager) getContext().getSystemService(Context.AUDIO_SERVICE);
            if (audio != null) {
                audio.setSpeakerphoneOn(false);
                audio.setMode(AudioManager.MODE_NORMAL);
            }
        } catch (Exception e) {
            // Leaving the phone in communication mode would be rude, but
            // there is nothing to be done if the system refuses.
        }
        call.resolve();
    }

    @PluginMethod
    public void stop(PluginCall call) {
        getContext().stopService(new Intent(getContext(), CallForegroundService.class));
        call.resolve();
    }
}
