<?php

/**
 * Craft JWT Auth plugin for Craft CMS 3.x
 *
 * Enable authentication to Craft through the use of JSON Web Tokens (JWT)
 *
 * @link      https://edenspiekermann.com
 * @copyright Copyright (c) 2019 Mike Pierce
 */

namespace levinriegner\craftcognitoauth\services\validators;

use Craft;
use craft\base\Component;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\helpers\ArrayHelper;
use levinriegner\craftcognitoauth\CraftJwtAuth;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\Token;

use CoderCat\JWKToPEM\JWKConverter;
use levinriegner\craftcognitoauth\services\AbstractValidator;

/**
 * @author    Mike Pierce
 * @package   CraftJwtAuth
 * @since     0.1.0
 */
class JWT extends AbstractValidator
{
    // Public Methods
    // =========================================================================

    /*
     * @return mixed
     */
    public function getTokenFromRequest()
    {
        // Look for an access token in the settings
        $accessToken = Craft::$app->request->headers->get('authorization') ?: Craft::$app->request->headers->get('x-access-token');

        // If "Bearer " is present, strip it to get the token.
        if (StringHelper::startsWith($accessToken, 'Bearer ')) {
            $accessToken = StringHelper::substr($accessToken, 7);
        }

        // If we find one, and it looks like a JWT...
        if ($accessToken) {
            return $accessToken;
        }

        return null;
    }

    /*
    * @return mixed
    */
    public function parseToken($accessToken)
    {
        if (count(explode('.', $accessToken)) === 3) {
            $token = (new Parser())->parse((string) $accessToken);

            return $token;
        }

        return null;
    }

    /*
    * @return mixed
    */
    public function verifyToken($token)
    {
        $jwksUrl = CraftJwtAuth::getInstance()->getSettings()->getJwks();
        $jwks = json_decode(file_get_contents($jwksUrl), true);
        $jwk = null;
        foreach($jwks['keys'] as $struct) {
            if ($token->getHeader('kid') === $struct['kid']) {
                $jwk = $struct;
                break;
            }
        }

        $jwkConverter = new JWKConverter();
        $convertedJwk = $jwkConverter->toPEM($jwk);

        // Attempt to verify the token
        $verify = $token->verify((new Sha256()), $convertedJwk);

        return $verify;
    }

    /*
    * @return mixed
    */
    public function getUserByToken($token)
    {
        if ($this->verifyJWT($token)) {
            // Derive the username from the subject in the token
            $email = $token->getClaim('email', '');
            $userName = $token->getClaim('sub', '');

            // Look for the user with email
            $user = Craft::$app->users->getUserByUsernameOrEmail($email ?: $userName);

            return $user;
        }

        return null;
    }

    /*
    * @return mixed
    */
    public function createUserByToken($token)
    {
        if ($this->verifyJWT($token)) {
            // Get relevant settings
            $autoCreateUser = CraftJwtAuth::getInstance()->getSettings()->getAutoCreateUser();

            if ($autoCreateUser) {
                // Create a new user and populate with claims
                $user = new User();

                // Email is a mandatory field
                if ($token->hasClaim('email')) {
                    $email = $token->getClaim('email');

                    // Set username and email
                    $user->email = $email;
                    $user->username = $token->getClaim('cognito:username', $email);

                    // These are optional, so pass empty string as the default
                    $user->firstName = $token->getClaim('given_name', '');
                    $user->lastName = $token->getClaim('family_name', '');

                    // Attempt to save the user
                    $success = Craft::$app->getElements()->saveElement($user);

                    // If user saved ok...
                    if ($success) {
                        // Assign the user to the default public group
                        Craft::$app->users->assignUserToDefaultGroup($user);

                        return $user;
                    }
                }
            }
        }

        return null;
    }
}