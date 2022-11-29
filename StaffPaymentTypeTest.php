<?php
declare(strict_types=1);

namespace StaffPayment;

use Glavfinans\Core\Entity\StaffPayment\StaffPaymentType;
use OutOfBoundsException;
use PHPUnit\Framework\TestCase;

/**
 * Тестирование сущности StaffPaymentType
 *
 * @package unit
 * @covers StaffPaymentType::class
 */
class StaffPaymentTypeTest extends TestCase
{

    /**
     * Проверка на выброс исключения, при не верном типе
     *
     * @covers StaffPaymentType::__construct()
     */
    public function testOutOfBoundsException()
    {
        $this->expectException(exception: OutOfBoundsException::class);

        new StaffPaymentType(code: 7);
    }

    /**
     * Проверка на корректность формирования и получения
     * типа
     * @covers StaffPaymentType::getCode
     */
    public function testGetCode()
    {
        $typePay = StaffPaymentType::makePay();
        $this->assertEquals(expected: 1, actual: $typePay->getCode());
    }

    /**
     * Проверка метода isEqual()
     *
     * @covers StaffPaymentType::isEqual()
     */
    public function testIsEqual()
    {
        $typePay = StaffPaymentType::makePay();
        $typePayRestructuring = StaffPaymentType::makePayRestructuring();

        $this->assertTrue($typePay->isEqual(clone $typePay));
        $this->assertFalse($typePay->isEqual($typePayRestructuring));
    }

    /**
     * Проверка метода CodeList()
     *
     * @covers StaffPaymentType::getCodeList()
     */
    public function testGetCodeList()
    {
        $codeList = StaffPaymentType::getCodeList();

        /** Проверяем что все значения в массиве имеют тип Int */
        foreach ($codeList as $code) {
            $this->assertIsInt(actual: $code);
        }

        /** Проверяем что массив типов состоит из уникальных элементов */
        $uniqueCodes = array_unique(array: $codeList);
        $this->assertEquals(expected: $codeList, actual: $uniqueCodes, message: 'Типы должны быть уникальными');
    }

    /**
     * Проверка метода getAssocList()
     *
     * @covers StaffPaymentType::getAssocList()
     */
    public function testGetAssocList()
    {
        $assocList = StaffPaymentType::getAssocList();

        /** Проверяем что все значения в массиве имеют тип string */
        foreach ($assocList as $code) {
            $this->assertIsString(actual: $code);
        }

        /** Проверяем что массив типов состоит из уникальных элементов */
        $uniqueCodes = array_unique(array: $assocList);
        $this->assertEquals(expected: $assocList, actual: $uniqueCodes, message: 'Типы должны быть уникальными');
    }

    /**
     * Проверка метода getList()
     *
     * @covers StaffPaymentType::getList()
     */
    public function testGetList()
    {
        $getList = StaffPaymentType::getList();

        /** Проверяем что вернулся массив объектов StaffPaymentType */
        $this->assertContainsOnlyInstancesOf(className: StaffPaymentType::class, haystack: $getList);

        /** Проверяем что вернулся массив с 6 элементами*/
        $this->assertCount(expectedCount: 6, haystack: $getList);
    }

    /**
     * Проверка метода isUnknown()
     *
     * @covers StaffPaymentType::isUnknown()
     */
    public function testIsUnknown()
    {
        $typeUnknown = StaffPaymentType::makeUnknown();
        $this->assertTrue($typeUnknown->isUnknown());

        $typePayRestructuring = StaffPaymentType::makePayRestructuring();
        $this->assertFalse($typePayRestructuring->isUnknown());
    }

    /**
     * Проверка метода isPay()
     *
     * @covers StaffPaymentType::isPay()
     */
    public function testIsPay()
    {
        $typePay = StaffPaymentType::makePay();
        $this->assertTrue($typePay->isPay());

        $typePayRestructuring = StaffPaymentType::makePayRestructuring();
        $this->assertFalse($typePayRestructuring->isPay());
    }

    /**
     * Проверка метода isProlong()
     *
     * @covers StaffPaymentType::isProlong()
     */
    public function testIsProlong()
    {
        $typeProlong = StaffPaymentType::makeProlong();
        $this->assertTrue($typeProlong->isProlong());

        $typePay = StaffPaymentType::makePay();
        $this->assertFalse($typePay->isProlong());
    }

    /**
     * Проверка метода isPayAll()
     *
     * @covers StaffPaymentType::isPayAll()
     */
    public function testIsPayAll()
    {
        $typePayAll = StaffPaymentType::makePayAll();
        $this->assertTrue($typePayAll->isPayAll());

        $typePay = StaffPaymentType::makePay();
        $this->assertFalse($typePay->isPayAll());
    }

    /**
     * Проверка метода isRestructuring()
     *
     * @covers StaffPaymentType::isRestructuring()
     */
    public function testIsRestructuring()
    {
        $typeRestructuring = StaffPaymentType::makeRestructuring();
        $this->assertTrue($typeRestructuring->isRestructuring());

        $typePay = StaffPaymentType::makePay();
        $this->assertFalse($typePay->isRestructuring());
    }

    /**
     * Проверка метода isPayRestructuring()
     *
     * @covers StaffPaymentType::isPayRestructuring()
     */
    public function testIsPayRestructuring()
    {
        $typePayRestructuring = StaffPaymentType::makePayRestructuring();
        $this->assertTrue($typePayRestructuring->isPayRestructuring());

        $typePay = StaffPaymentType::makePay();
        $this->assertFalse($typePay->isPayRestructuring());
    }
}
