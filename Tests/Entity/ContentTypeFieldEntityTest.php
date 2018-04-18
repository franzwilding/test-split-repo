<?php

namespace UniteCMS\CoreBundle\Tests\Entity;

use PHPUnit\Framework\TestCase;
use UniteCMS\CoreBundle\Entity\ContentTypeField;
use UniteCMS\CoreBundle\Entity\Organization;

class ContentTypeFieldEntityTest extends TestCase
{
    public function testBasicOperations()
    {
        $field = new ContentTypeField();
        $field
            ->setTitle('Title')
            ->setId(300);

        // test if id was set
        $this->assertEquals(300, $field->getId());

        // test if title is correct
        $this->assertEquals('Title', $field->__toString());
    }

    /**
     * @expectedException \InvalidArgumentException
     */
    public function testSetEntityException()
    {
        $field = new ContentTypeField();
        $field->setEntity(new Organization());
    }
}