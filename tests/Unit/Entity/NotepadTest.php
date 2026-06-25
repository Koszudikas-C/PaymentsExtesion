<?php

declare(strict_types=1);

namespace Tests\Unit\Entity;

use App\Entity\Customer;
use App\Entity\Notepad;
use PHPUnit\Framework\TestCase;

class NotepadTest extends TestCase
{
    private Customer $customer;
    private string $jid = 'test_jid_123';
    private string $ownerJid = 'owner_jid_456';

    protected function setUp(): void
    {
        $this->customer = new Customer('Test User', 'test@example.com', '5511999999999');
    }

    public function testConstructorAndGetters(): void
    {
        $notepad = new Notepad($this->customer, $this->jid, $this->ownerJid);

        $this->assertSame($this->customer, $notepad->getCustomer(), 'Customer should be set via constructor');
        $this->assertEquals($this->jid, $notepad->getJid(), 'JID should match the one passed to constructor');
        $this->assertEquals($this->ownerJid, $notepad->getOwnerJid(), 'Owner JID should match the one passed to constructor');
        $this->assertNull($notepad->getNote(), 'Note should be null initially');
    }

    public function testSetAndGetNote(): void
    {
        $notepad = new Notepad($this->customer, $this->jid);
        $note = 'This is a sample note.';
        $notepad->setNote($note);
        $this->assertEquals($note, $notepad->getNote());
    }

    public function testSetAndGetOwnerJid(): void
    {
        $notepad = new Notepad($this->customer, $this->jid);
        $this->assertNull($notepad->getOwnerJid(), 'Owner JID should be null when not provided');
        $notepad->setOwnerJid($this->ownerJid);
        $this->assertEquals($this->ownerJid, $notepad->getOwnerJid());
    }
}
?>
