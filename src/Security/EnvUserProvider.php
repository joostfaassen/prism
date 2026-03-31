<?php

namespace App\Security;

use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * @implements UserProviderInterface<InMemoryUser>
 */
class EnvUserProvider implements UserProviderInterface
{
    public function __construct(
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    public function loadUserByIdentifier(string $identifier): UserInterface
    {
        if ($identifier === $this->username) {
            return new InMemoryUser($this->username, $this->password, ['ROLE_ADMIN']);
        }

        throw new UserNotFoundException(sprintf('User "%s" not found.', $identifier));
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === InMemoryUser::class;
    }
}
