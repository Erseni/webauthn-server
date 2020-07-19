<?php

namespace MadWizard\WebAuthn\Tests\Attestation;

use MadWizard\WebAuthn\Attestation\AttestationObject;
use MadWizard\WebAuthn\Attestation\Registry\BuiltInAttestationFormat;
use MadWizard\WebAuthn\Attestation\Statement\AttestationStatementInterface;
use MadWizard\WebAuthn\Attestation\Verifier\AttestationVerifierInterface;
use MadWizard\WebAuthn\Format\ByteBuffer;
use PHPUnit\Framework\TestCase;

class BuiltInAttestationFormatTest extends TestCase
{
    public function test()
    {
        $this->getMockBuilder(AttestationStatementInterface::class)
            ->setMockClassName('TestStatement')
            ->getMock();
        $this->getMockBuilder(AttestationVerifierInterface::class)
            ->setMockClassName('TestVerifier')
            ->getMock();

        $format = new BuiltInAttestationFormat('testformat', 'TestStatement', 'TestVerifier');
        $this->assertSame('testformat', $format->getFormatId());

        $attObj = new AttestationObject('dummy', [], new ByteBuffer(''));

        $statement = $format->createStatement($attObj);
        $this->assertInstanceOf('TestStatement', $statement);
        $verifier = $format->getVerifier();
        $this->assertInstanceOf('TestVerifier', $verifier);
    }
}
