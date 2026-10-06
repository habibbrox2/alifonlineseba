package online.broxlab.aliftools.twa;

import android.content.pm.ActivityInfo;
import android.os.Build;
import android.os.Bundle;

/**
 * The app's only activity.
 *
 * <p>Everything below {@link com.google.androidbrowserhelper.trusted.LauncherActivity} does
 * the real work: reading {@code DEFAULT_URL} and the rest of the meta-data out of the
 * manifest and handing the page to Chrome. This subclass exists to pin the orientation.
 *
 * <p>As in Bubblewrap's own template, the orientation is only forced on Oreo and above.
 * On older releases the TWA window is translucent, and {@code setRequestedOrientation} on a
 * translucent activity crashes there — see
 * https://github.com/GoogleChromeLabs/bubblewrap/issues/496. Chrome honours the orientation
 * on those versions regardless.
 */
public class LauncherActivity extends com.google.androidbrowserhelper.trusted.LauncherActivity {

    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);

        if (Build.VERSION.SDK_INT > Build.VERSION_CODES.O) {
            setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_PORTRAIT);
        } else {
            setRequestedOrientation(ActivityInfo.SCREEN_ORIENTATION_UNSPECIFIED);
        }
    }
}
