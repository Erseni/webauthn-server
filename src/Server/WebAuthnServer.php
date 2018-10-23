<?php


namespace MadWizard\WebAuthn\Server;

use MadWizard\WebAuthn\Attestation\Registry\AttestationFormatRegistry;
use MadWizard\WebAuthn\Attestation\Registry\AttestationFormatRegistryInterface;
use MadWizard\WebAuthn\Config\WebAuthnConfiguration;
use MadWizard\WebAuthn\Config\WebAuthnConfigurationInterface;
use MadWizard\WebAuthn\Credential\CredentialRegistration;
use MadWizard\WebAuthn\Credential\CredentialStoreInterface;
use MadWizard\WebAuthn\Dom\AuthenticatorTransport;
use MadWizard\WebAuthn\Dom\PublicKeyCredentialCreationOptions;
use MadWizard\WebAuthn\Dom\PublicKeyCredentialDescriptor;
use MadWizard\WebAuthn\Dom\PublicKeyCredentialInterface;
use MadWizard\WebAuthn\Dom\PublicKeyCredentialParameters;
use MadWizard\WebAuthn\Dom\PublicKeyCredentialRequestOptions;
use MadWizard\WebAuthn\Dom\PublicKeyCredentialUserEntity;
use MadWizard\WebAuthn\Exception\ParseException;
use MadWizard\WebAuthn\Exception\VerificationException;
use MadWizard\WebAuthn\Exception\WebAuthnException;
use MadWizard\WebAuthn\Format\Base64UrlEncoding;
use MadWizard\WebAuthn\Format\ByteBuffer;
use MadWizard\WebAuthn\Json\JsonConverter;
use MadWizard\WebAuthn\Server\Authentication\AssertionContext;
use MadWizard\WebAuthn\Server\Authentication\AssertionVerifier;
use MadWizard\WebAuthn\Server\Authentication\AuthenticationOptions;
use MadWizard\WebAuthn\Server\Authentication\AuthenticationRequest;
use MadWizard\WebAuthn\Server\Authentication\AuthenticationResult;
use MadWizard\WebAuthn\Server\Registration\AttestationContext;
use MadWizard\WebAuthn\Server\Registration\AttestationResult;
use MadWizard\WebAuthn\Server\Registration\AttestationVerifier;
use MadWizard\WebAuthn\Server\Registration\RegistrationOptions;
use MadWizard\WebAuthn\Server\Registration\RegistrationRequest;

class WebAuthnServer
{
    /**
     * @var WebAuthnConfiguration
     */
    private $config;

    /**
     * @var AttestationFormatRegistryInterface|null
     */
    private $formatRegistry;

    /**
     * @var CredentialStoreInterface
     */
    private $credentialStore;

    public function __construct(WebAuthnConfigurationInterface $config, CredentialStoreInterface $credentialStore)
    {
        $this->config = $config;
        $this->credentialStore = $credentialStore;
    }

    public function startRegistration(RegistrationOptions $options) : RegistrationRequest
    {
        $challenge = $this->createChallenge();

        $creationOptions = new PublicKeyCredentialCreationOptions(
            $this->config->getRelyingPartyEntity(),
            $this->createUserEntity($options->getUser()),
            $challenge,
            $this->getCredentialParameters()
        );

        $creationOptions->setAttestation($options->getAttestation());
        $creationOptions->setAuthenticatorSelection($options->getAuthenticatorSelection());

        $context = AttestationContext::create($creationOptions, $this->config);
        return new RegistrationRequest($creationOptions, $context);
    }

    /**
     * @param PublicKeyCredentialInterface|string $credential object or JSON serialized representation from the client.
     * @param AttestationContext $context
     * @return AttestationResult
     */
    public function finishRegistration($credential, AttestationContext $context) : AttestationResult
    {
        $credential = $this->convertAttestationCredential($credential);
        $verifier = new AttestationVerifier($this->getFormatRegistry());
        $attestationResult = $verifier->verify($credential, $context);

        $registration = new CredentialRegistration($attestationResult->getCredentialId(), $attestationResult->getPublicKey(), $context->getUserHandle());
        $this->credentialStore->registerCredential($registration);
        return $attestationResult;
    }

    public function startAuthentication(AuthenticationOptions $options) : AuthenticationRequest
    {
        $challenge = $this->createChallenge();

        $requestOptions = new PublicKeyCredentialRequestOptions($challenge);
        $requestOptions->setRpId($this->config->getRelyingPartyId());

        $this->addAllowCredentials($options, $requestOptions);


        $context = AssertionContext::create($requestOptions, $this->config);
        return new AuthenticationRequest($requestOptions, $context);
    }

    /**
     * @param PublicKeyCredentialInterface|string $credential object or JSON serialized representation from the client.
     * @param AssertionContext $context
     * @return AuthenticationResult
     */
    public function finishAuthentication($credential, AssertionContext $context) : AuthenticationResult
    {
        $credential = $this->convertAssertionCredential($credential);

        $verifier = new AssertionVerifier($this->credentialStore);

        $userCredential = $verifier->verifyAuthenticatonAssertion($credential, $context);

        return new AuthenticationResult($userCredential);
    }

    /**
     * @param AuthenticationOptions $options
     * @param PublicKeyCredentialRequestOptions $requestOptions
     * @throws WebAuthnException
     */
    private function addAllowCredentials(AuthenticationOptions $options, PublicKeyCredentialRequestOptions $requestOptions): void
    {
        $credentials = $options->getAllowCredentials();
        $transports = AuthenticatorTransport::allKnownTransports(); // TODO: from config
        if (count($credentials) > 0) {
            foreach ($credentials as $credential) {
                $credentialId = new ByteBuffer(Base64UrlEncoding::decode($credential->getCredentialId()));
                $descriptor = new PublicKeyCredentialDescriptor($credentialId);
                foreach ($transports as $transport) {
                    $descriptor->addTransport($transport);
                }
                $requestOptions->addAllowedCredential($descriptor);
            }
        }
    }

    private function createUserEntity(UserIdentity $user) : PublicKeyCredentialUserEntity
    {
        return new PublicKeyCredentialUserEntity(
            $user->getUsername(),
            $user->getUserHandle(),
            $user->getDisplayName()
        );
    }

    /**
     * @return PublicKeyCredentialParameters[]
     */
    private function getCredentialParameters() : array
    {
        $parameters = [];
        $algorithms = $this->config->getAllowedAlgorithms();
        foreach ($algorithms as $algorithm) {
            $parameters[] = new PublicKeyCredentialParameters($algorithm);
        }
        return $parameters;
    }

    private function createChallenge() : ByteBuffer
    {
        return ByteBuffer::randomBuffer($this->config->getChallengeLength());
    }

    private function convertAttestationCredential($credential) : PublicKeyCredentialInterface
    {
        if (\is_string($credential)) {
            return JsonConverter::decodeAttestationCredential($credential);
        }

        if ($credential instanceof PublicKeyCredentialInterface) {
            return $credential;
        }

        throw new WebAuthnException('Parameter credential should be of type string or PublicKeyCredentialInterface.');
    }

    private function convertAssertionCredential($credential) : PublicKeyCredentialInterface
    {
        if (\is_string($credential)) {
            try {
                return JsonConverter::decodeAssertionCredential($credential);
            } catch (ParseException $e) {
                throw new VerificationException('Failed to parse JSON client data', 0, $e);
            }
        }

        if ($credential instanceof PublicKeyCredentialInterface) {
            return $credential;
        }

        throw new WebAuthnException('Parameter credential should be of type string or PublicKeyCredentialInterface.');
    }

    public function getFormatRegistry() : AttestationFormatRegistryInterface
    {
        if ($this->formatRegistry === null) {
            $this->formatRegistry = $this->createDefaultFormatRegistry();
        }

        return $this->formatRegistry;
    }

    private function createDefaultFormatRegistry() : AttestationFormatRegistry
    {
        $registry = new AttestationFormatRegistry();
        $formats = $this->config->getAttestationFormats();
        foreach ($formats as $format) {
            $registry->addFormat($format);
        }
        return $registry;
    }
}
