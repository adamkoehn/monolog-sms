<?php

namespace Tylercd100\Monolog\Tests;

use Exception;
use Monolog\Logger;
use Monolog\Test\TestCase;
use Tylercd100\Monolog\Formatter\SMSFormatter;
use Tylercd100\Monolog\Handler\PlivoHandler;
use Tylercd100\Monolog\Handler\SMSHandler;

class PlivoHandlerTest extends TestCase
{
    public function testCanBeInstantiatedAndProvidesDefaultFormatter(): void
    {
        $handler = new PlivoHandler('token', 'auth_id', '+15555555555', '+16666666666');

        $this->assertInstanceOf(SMSFormatter::class, $handler->getFormatter());
    }

    public function testItThrowsExceptionWhenUsingDifferentVersionOtherThanV1(): void
    {
        self::expectException(Exception::class);
        new PlivoHandler('token', 'auth_id', '+15555555555', '+16666666666', Logger::CRITICAL, true, true, 'localhost', 'v2');
    }

    public function testItThrowsExceptionWhenVersionIsEmpty(): void
    {
        self::expectException(Exception::class);
        new class('token', 'auth_id', '+15555555555', '+16666666666') extends SMSHandler {

            protected function buildContent(array $record): string
            {
                return '';
            }

            protected function buildRequestUrl(): string
            {
                return '';
            }
        };
    }

    public function testWriteCustomHostHeader(): void
    {
        $payload = $this->createPayloadFromMessage('test1');

        $this->assertRequestSame('POST /v1/Account/auth_id/Message/ HTTP/1.1', $payload);
        $this->assertHeadersContain('Host: localhost', $payload);
        $this->assertHeadersContain('Authorization: Basic YXV0aF9pZDp0b2tlbg==', $payload);
        $this->assertHeadersContain('Content-Type: application/json', $payload);
        $this->assertHeadersContain('Content-Length: 58', $payload);
    }

    public function testWriteContent(): void
    {
        $payload = $this->createPayloadFromMessage('test1');

        $this->assertBodySame('{"src":"+15555555555","dst":"+16666666666","text":"test1"}', $payload);
    }

    public function testWriteContentV1WithoutToAndFromNumbers(): void
    {
        $payload = $this->createPayloadFromMessage('test1', null, null);

        $this->assertBodySame('{"src":null,"dst":null,"text":"test1"}', $payload);
    }

    public function testWriteContentNotify(): void
    {
        $payload = $this->createPayloadFromMessage('test1');

        $this->assertBodySame('{"src":"+15555555555","dst":"+16666666666","text":"test1"}', $payload);
    }

    public function testWriteWithComplexMessage(): void
    {
        $payload = $this->createPayloadFromMessage('Backup of database example finished in 16 minutes.');

        $this->assertBodySame('{"src":"+15555555555","dst":"+16666666666","text":"Backup of database example finished in 16 minutes."}', $payload);
    }

    public function testMaximumBodySizeLimit(): void
    {
        $payload = $this->createPayloadFromMessage(
            'This is a message that is intentionally long, more than the 160 character limit allowed by the default limit on the Plivo handler. '
            . 'The Plivo handler will truncate this message at 160 characters before encoding it to send to the API.'
        );

        $this->assertBodySame('{"src":"+15555555555","dst":"+16666666666","text":"This is a message that is intentionally long, more than the 160 character limit allowed by the default limit on the Plivo handler. The Plivo handler will trunca"}', $payload);
    }

    private function assertBodySame(string $expected, array $payload): void
    {
        self::assertSame($expected, $payload['body']);
    }

    private function assertHeadersContain(string $expected, array $payload): void
    {
        self::assertContains($expected, $payload['headers']);
    }

    private function assertRequestSame(string $expected, array $payload): void
    {
        self::assertSame($expected, $payload['request']);
    }

    private function createPayloadFromMessage(string $message, ?string $fromNumber = '+15555555555', ?string $toNumber = '+16666666666'): array
    {
        $res = fopen('php://memory', 'a');
        $handler = $this->buildHandlerMock($res, $fromNumber, $toNumber);
        $handler->handle($this->getRecord(Logger::CRITICAL, $message));
        fseek($res, 0);

        return $this->parsePayload(fread($res, 1024));
    }

    private function buildHandlerMock($res, ?string $fromNumber, ?string $toNumber): PlivoHandler
    {
        $constructorArgs = ['token', 'auth_id', $fromNumber, $toNumber, Logger::DEBUG, true, true, 'localhost', PlivoHandler::API_V1];
        $handler = $this->getMockBuilder(PlivoHandler::class)
            ->setConstructorArgs($constructorArgs)
            ->onlyMethods(['fsockopen', 'streamSetTimeout', 'closeSocket'])
            ->getMock();

        $handler->expects($this->once())
            ->method('fsockopen')
            ->will($this->returnValue($res));
        $handler->expects($this->once())
            ->method('streamSetTimeout')
            ->will($this->returnValue(true));
        $handler->expects($this->once())
            ->method('closeSocket')
            ->will($this->returnValue(true));

        $handler->setFormatter($this->getIdentityFormatter());

        return $handler;
    }

    private function parsePayload(string $content): array
    {
        $array = explode("\r\n", $content);

        return [
            'request' => $array[0],
            'headers' => array_slice($array, 1, 4),
            'body' => $array[6],
        ];
    }
}
