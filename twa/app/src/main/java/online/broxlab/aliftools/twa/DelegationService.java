package online.broxlab.aliftools.twa;

/**
 * Hosts the Trusted Web Activity and receives the notifications the site's Service Worker
 * pushes.
 *
 * <p>This class is named in the manifest, never instantiated by hand: the library's
 * {@code DelegationService} is what Chrome binds to when the TWA starts, and this empty
 * subclass is the seam where per-app notification handling would go. It must stay in the
 * source set — removing it breaks the manifest reference and the app will not install.
 */
public class DelegationService extends com.google.androidbrowserhelper.trusted.DelegationService {
}
