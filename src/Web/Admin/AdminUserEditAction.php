<?php

declare(strict_types=1);

namespace App\Web\Admin;

use App\Auth\Identity;
use App\Repository\ActivityLogRepository;
use App\Repository\UserRepository;
use App\Service\ServiceDate;
use App\Service\UserFields;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Yiisoft\Router\CurrentRoute;
use Yiisoft\Router\UrlGeneratorInterface;
use Yiisoft\Session\SessionInterface;
use Yiisoft\Yii\View\Renderer\WebViewRenderer;

/**
 * One user's record, editable.
 *
 * The list at `/admin/users` is built for scanning: a row shows the name, the
 * balance and two one-click switches. Everything a row cannot hold — the
 * person's name, the handles they sign in with, a birth date — is what this
 * page is for, which is also why role and status are *not* here: the list
 * already changes both in a single click, and a second control for either
 * would be two places to disagree.
 *
 * The fields are validated by {@see UserFields}, the same class the create
 * form uses, and the date is rendered by `components/date-field.twig`, the
 * same widget every other date in the product uses. A rejected submit
 * re-renders this page with the typed values rather than redirecting, so a
 * mistyped birth date is corrected in place instead of being retyped from
 * scratch.
 */
final readonly class AdminUserEditAction
{
    public function __construct(
        private WebViewRenderer $view,
        private UserRepository $users,
        private ActivityLogRepository $logs,
        private SessionInterface $session,
        private UrlGeneratorInterface $url,
    ) {}

    public function __invoke(ServerRequestInterface $request, CurrentRoute $route): ResponseInterface
    {
        /** @var Identity $identity */
        $identity = $request->getAttribute('identity');

        $id = (int) $route->getArgument('id', '0');
        // Trashed rows stay editable: fixing a typo is a better answer than
        // restoring an account, and `findById()` needs the flag to see it.
        $row = $this->users->findById($id, true);

        if ($row === null) {
            $this->session->set('flash_error', 'ইউজার পাওয়া যায়নি।');

            return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('admin-users')]);
        }

        $errors = [];

        if ($request->getMethod() === 'POST') {
            $input = (array) $request->getParsedBody();

            $checked = UserFields::validate($input);
            $values = $checked['values'];
            $errors = $checked['errors'];

            // Uniqueness has to exclude this very row, or saving an unchanged
            // username would report a conflict with itself.
            if (!isset($errors['username']) && $this->users->usernameExists($values['username'], $id)) {
                $errors['username'] = 'এই ইউজারনেম আগে থেকেই আছে।';
            }
            if (!isset($errors['phone']) && $this->users->phoneExists($values['phone'], $id)) {
                $errors['phone'] = 'এই নম্বরে অ্যাকাউন্ট আছে।';
            }

            if ($errors === []) {
                $this->users->update($id, $values);

                $this->logs->create([
                    'user_id' => $identity->id,
                    'action' => 'admin.user.updated',
                    'description' => "User '{$row['username']}' updated by admin",
                    'metadata' => ['user_id' => $id],
                ]);
                $this->session->set('flash_success', 'ইউজারের তথ্য আপডেট হয়েছে।');

                return new \Nyholm\Psr7\Response(302, ['Location' => $this->url->generate('admin-users')]);
            }

            // Show what was typed, not the stored row, so a rejected save does
            // not silently undo every other field the operator just corrected.
            $row = array_merge($row, $values);
        }

        return $this->view->render('site/admin/user-edit.twig', [
            'row' => $row,
            'errors' => $errors,
            // `2026-10-06` in the row, `06-10-2026` in the field — and, after a
            // rejected save, the raw text that was typed, which ServiceDate
            // hands back unchanged because it could not be parsed.
            // `csrf` and `identity` are common view parameters, injected for
            // every page; passing them again here would just be a second
            // source for the same token.
            'birthDateDisplay' => ServiceDate::display($row['date_of_birth'] ?? ''),
        ]);
    }
}