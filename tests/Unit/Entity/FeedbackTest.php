<?php

namespace Tests\Unit\Entity;

use App\Entity\Customer;
use App\Entity\Feedback;
use PHPUnit\Framework\TestCase;

class FeedbackTest extends TestCase
{
    public function testConstructorInitializesFeedback()
    {
        $customer = $this->createMock(Customer::class);
        $type = 'EVALUATION';
        $message = 'Ótimo serviço!';
        $rating = 5;

        $feedback = new Feedback($type, $message, $rating, $customer);

        $this->assertEquals($type, $feedback->getType());
        $this->assertEquals($message, $feedback->getMessage());
        $this->assertEquals($rating, $feedback->getRating());
        $this->assertSame($customer, $feedback->getCustomer());
    }

    public function testConstructorAllowsNullRatingAndCustomer()
    {
        $type = 'FEATURE_REQUEST';
        $message = 'Gostaria de uma nova função.';

        $feedback = new Feedback($type, $message);

        $this->assertEquals($type, $feedback->getType());
        $this->assertEquals($message, $feedback->getMessage());
        $this->assertNull($feedback->getRating());
        $this->assertNull($feedback->getCustomer());
    }

    public function testOnPreUpdateUpdatesDateUpdated()
    {
        $feedback = new Feedback('TEST', 'Msg');
        
        // Simular data antiga
        $oldDate = (new \DateTime('now'))->modify('-2 days');
        
        $reflection = new \ReflectionClass($feedback);
        $prop = $reflection->getProperty('dateUpdated');
        $prop->setValue($feedback, $oldDate);

        $this->assertEquals($oldDate->format('Y-m-d H:i:s'), $feedback->getDateUpdated()->format('Y-m-d H:i:s'));

        $feedback->onPreUpdate();

        $this->assertGreaterThan($oldDate, $feedback->getDateUpdated());
        $this->assertEquals((new \DateTime('now'))->format('Y-m-d'), $feedback->getDateUpdated()->format('Y-m-d'));
    }
}
