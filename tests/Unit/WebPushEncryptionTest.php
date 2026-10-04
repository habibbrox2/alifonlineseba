<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Notification\Channel\WebPushChannel;
use Codeception\Test\Unit;
use PHPUnit\Framework\Assert;

use function base64_decode;
use function base64_encode;
use function bin2hex;
use function hash;
use function hash_hmac;
use function hexdec;
use function is_string;
use function openssl_decrypt;
use function openssl_pkey_derive;
use function openssl_pkey_get_details;
use function openssl_pkey_get_private;
use function openssl_pkey_get_public;
use function openssl_pkey_new;
use function rtrim;
use function str_replace;
use function strlen;
use function substr;

/**
 * RFC 8291 aes128gcm round trip.
 *
 * The test plays the part of the *subscriber's browser*: it holds a private
 * key that WebPushChannel never sees, and decrypts the sealed body with
 * nothing but the public information the channel was given. That is the only
 * way to prove the sealed bytes are actually well formed — a channel that
 * returns "some bytes of the right length" passes every shape check and still
 * gets silently dropped by the push service.
 *
 * This test earned its place the hard way. `encrypt()` compared a decoded
 * string against an integer (`$authSecret !== 16`), which is true for every
 * input, so every subscription was rejected as unencryptable and no browser
 * ever received a message.
 */
final class WebPushEncryptionTest extends Unit
{
    private const PLAINTEXT = '{"title":"Result file ready","body":"Your service is live."}';

    public function testSealedBodyIsDecryptableByTheSubscribersBrowser(): void
    {
        $subscriber = $this->subscriberKeyPair();
        $sealed = $this->seal($subscriber['p256dh'], $subscriber['auth'], self::PLAINTEXT);

        Assert::assertNotNull($sealed, 'encrypt() returned null for a valid subscription');
        Assert::assertGreaterThan(
            86 + 16,
            strlen($sealed),
            'the sealed body must be at least the 86-byte header plus a 16-byte tag'
        );

        Assert::assertSame(self::PLAINTEXT, $this->openAsBrowser($sealed, $subscriber['privatePem']));
    }

    public function testSealedBodyCarriesAWellFormedHeader(): void
    {
        $subscriber = $this->subscriberKeyPair();
        $sealed = $this->seal($subscriber['p256dh'], $subscriber['auth'], 'x');

        Assert::assertNotNull($sealed);
        $salt = substr($sealed, 0, 16);
        $rs = substr($sealed, 16, 4);
        $idlen = ord($sealed[20]);
        $senderPublic = substr($sealed, 21, $idlen);

        // rs is the record size, a big-endian uint32. 4096 is what every push
        // service expects and what a 4000-byte body can never exceed.
        Assert::assertSame(4096, unpack('N', $rs)[1]);
        Assert::assertSame(65, $idlen, 'idlen must be 65 for an uncompressed P-256 point');
        Assert::assertSame("\x04", $senderPublic[0], 'the sender key must be an uncompressed point');
        Assert::assertNotFalse(openssl_pkey_get_public($this->pointAsPem($senderPublic)));

        // A fresh salt per message: reusing one across two messages leaks the
        // GCM nonce and destroys the confidentiality of both.
        $second = $this->seal($subscriber['p256dh'], $subscriber['auth'], 'x');
        Assert::assertNotNull($second);
        Assert::assertNotSame(bin2hex($salt), bin2hex(substr($second, 0, 16)), 'the salt must be random per message');
        Assert::assertNotSame(
            bin2hex($senderPublic),
            bin2hex(substr($second, 21, $idlen)),
            'the ephemeral sender key must be fresh per message'
        );
    }

    public function testTwoMessagesToOneSubscriberGetDifferentSenderKeys(): void
    {
        $subscriber = $this->subscriberKeyPair();

        $first = $this->seal($subscriber['p256dh'], $subscriber['auth'], 'first');
        $second = $this->seal($subscriber['p256dh'], $subscriber['auth'], 'second');

        Assert::assertNotNull($first);
        Assert::assertNotNull($second);
        Assert::assertSame('first', $this->openAsBrowser($first, $subscriber['privatePem']));
        Assert::assertSame('second', $this->openAsBrowser($second, $subscriber['privatePem']));
    }

    public function testAuthSecretOfTheWrongLengthIsRejected(): void
    {
        $subscriber = $this->subscriberKeyPair();

        foreach (['', 'AAAA', str_repeat('A', 21), str_repeat('A', 24)] as $bad) {
            Assert::assertNull(
                $this->seal($subscriber['p256dh'], $bad, 'x'),
                'an auth secret that is not 16 bytes must be rejected, got ' . strlen($bad)
            );
        }
    }

    public function testPublicKeyThatIsNotAnUncompressedPointIsRejected(): void
    {
        $subscriber = $this->subscriberKeyPair();

        // 64 bytes: a bare x||y with no 0x04 prefix.
        $bare = substr($this->base64UrlDecode($subscriber['p256dh']), 1);
        Assert::assertNull($this->seal(rtrim(strtr(base64_encode($bare), '+/', '-_'), '='), $subscriber['auth'], 'x'));

        // 65 bytes but the wrong prefix.
        $wrongPrefix = "\x05" . substr($this->base64UrlDecode($subscriber['p256dh']), 1);
        Assert::assertNull(
            $this->seal(rtrim(strtr(base64_encode($wrongPrefix), '+/', '-_'), '='), $subscriber['auth'], 'x')
        );
    }

    /**
     * Call the private encrypt() the way sendToOne() does.
     *
     * Reflection is used deliberately: the method is private because callers
     * must not hand it arbitrary plaintext, and this test is the one place
     * that legitimately needs to see the sealed bytes without a network call.
     */
    private function seal(string $p256dh, string $auth, string $plaintext): ?string
    {
        $channel = (new \ReflectionClass(WebPushChannel::class))->newInstanceWithoutConstructor();
        $encrypt = new \ReflectionMethod(WebPushChannel::class, 'encrypt');

        $result = $encrypt->invoke($channel, $plaintext, $p256dh, $auth);

        return is_string($result) ? $result : null;
    }

    /**
     * Subscriber side of RFC 8291 + RFC 8188, implemented from the RFCs rather
     * than shared with the channel so that a mistake in the channel cannot
     * cancel itself out.
     */
    private function openAsBrowser(string $sealed, string $privatePem): string
    {
        $idlen = ord($sealed[20]);
        $senderPublic = substr($sealed, 21, $idlen);
        $salt = substr($sealed, 0, 16);
        $body = substr($sealed, 21 + $idlen);
        $ciphertext = substr($body, 0, -16);
        $tag = substr($body, -16);

        $private = openssl_pkey_get_private($privatePem);
        Assert::assertNotFalse($private, 'the test subscriber key must be usable');

        $sender = openssl_pkey_get_public($this->pointAsPem($senderPublic));
        Assert::assertNotFalse($sender, 'the sender public key in the header must be a valid P-256 point');

        // The arguments are deliberately in this order. PHP documents
        // openssl_pkey_derive() as (private, public), but the extension picks
        // the operands by inspecting the key type, and on this build the
        // (private, public) order returns false while (public, private)
        // succeeds. WebPushChannel::deriveSharedSecret() passes them the same
        // way round for the same reason; swapping either one silently breaks
        // every push.
        $sharedSecret = @openssl_pkey_derive($sender, $private, 32);
        Assert::assertNotFalse($sharedSecret, 'ECDH must produce a shared secret');
        Assert::assertSame(32, strlen((string) $sharedSecret));

        $myPublic = $this->subscriberPoint;
        $authSecret = $this->authSecret;

        $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
        $ikm = $this->hkdfExpand('sha256', $prkKey, "WebPush: info\x00" . $myPublic . $senderPublic, 32);
        $prk = hash_hmac('sha256', $ikm, $salt, true);
        $cek = $this->hkdfExpand('sha256', $prk, "Content-Encoding: aes128gcm\x00", 16);
        $nonce = $this->hkdfExpand('sha256', $prk, "Content-Encoding: nonce\x00", 12);

        $plaintext = openssl_decrypt($ciphertext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        Assert::assertNotFalse($plaintext, 'the sealed body must decrypt with the derived keys');

        // The last record's padding delimiter is 0x02, which is how a reader
        // knows the message ended cleanly rather than being truncated.
        Assert::assertSame("\x02", substr($plaintext, -1), 'RFC 8188 padding delimiter must be 0x02');

        return substr($plaintext, 0, -1);
    }

    /** @return array{p256dh: string, auth: string, privatePem: string, point: string} */
    private function subscriberKeyPair(): array
    {
        $pem = $this->runOpenssl(['openssl', 'ecparam', '-genkey', '-name', 'prime256v1'], null);
        Assert::assertNotNull($pem, 'openssl ecparam -genkey must succeed');
        Assert::assertStringContainsString('BEGIN', $pem);

        $key = openssl_pkey_get_private($pem);
        Assert::assertNotFalse($key, 'the generated subscriber key must be loadable');

        // A browser does not expose its public key through an OpenSSL key
        // object either; it hands the site a base64url point. The binary's
        // SubjectPublicKeyInfo DER ends with exactly those 65 bytes.
        $der = $this->runOpenssl(['openssl', 'ec', '-pubout', '-outform', 'DER'], $pem);
        Assert::assertNotNull($der);
        $point = substr($der, -65);
        Assert::assertSame("\x04", $point[0]);

        $this->authSecret = random_bytes(16);
        $this->subscriberPoint = $point;

        return [
            'p256dh' => $this->b64u($point),
            'auth' => $this->b64u($this->authSecret),
            'privatePem' => $pem,
            'point' => $point,
        ];
    }

    private string $authSecret = '';

    /** The subscriber's own public point, needed to redo the IKM derivation. */
    private string $subscriberPoint = '';

    /**
     * Run an openssl subcommand and return its stdout, or null on failure.
     *
     * @param list<string> $command
     */
    private function runOpenssl(array $command, ?string $stdin): ?string
    {
        $process = @proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        Assert::assertIsResource($process, 'the openssl binary must be runnable');
        if ($stdin !== null) {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 && is_string($stdout) ? $stdout : null;
    }

    /** Wrap a point as a SubjectPublicKeyInfo PEM so OpenSSL accepts it. */
    private function pointAsPem(string $point65): string
    {
        $tlv = static function (string $tag, string $content): string {
            $length = strlen($content);
            return $tag . ($length < 0x80 ? chr($length) : chr(0x81) . chr($length)) . $content;
        };

        $algorithm = $tlv("\x30", $tlv("\x06", "\x2a\x86\x48\xce\x3d\x02\x01")
            . $tlv("\x06", "\x2a\x86\x48\xce\x3d\x03\x01\x07"));
        $der = $tlv("\x30", $algorithm . $tlv("\x03", "\x00" . $point65));

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private function hkdfExpand(string $hash, string $prk, string $info, int $length): string
    {
        $output = '';
        $block = '';
        for ($i = 1; strlen($output) < $length; $i++) {
            $block = hash_hmac($hash, $block . $info . chr($i), $prk, true);
            $output .= $block;
        }

        return substr($output, 0, $length);
    }

    private function b64u(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
