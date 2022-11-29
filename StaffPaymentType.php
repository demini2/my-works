<?php
declare(strict_types=1);

namespace Glavfinans\Core\Entity\StaffPayment;

use Glavfinans\Core\ValueObject\ValueObjectInterface;
use OutOfBoundsException;
use PDO;
use Cycle\Database\DatabaseInterface;

/**
 * VO Типа оплаты
 *
 * @package Glavfinans\Core\Entity\StaffPayment
 */
class StaffPaymentType implements ValueObjectInterface
{
    /** @const Неизвестный тип оплаты */
    private const UNKNOWN = 0;

    /** @const Просто оплата, не продление и не закрытие */
    private const PAY = 1;

    /** @const Продление / Закрытие */
    private const PROLONG = 2;

    /** @const Полная оплата */
    private const PAY_ALL = 3;

    /** @const Оформление реструктуризации */
    private const RESTRUCTURING = 4;

    /** @const Оплата по реструктуризации */
    private const PAY_RESTRUCTURING = 5;

    /**
     * @param int $code - Тип оплаты
     */
    public function __construct(private int $code)
    {
        if (!in_array(needle: $code, haystack: self::getCodeList())) {
            throw new OutOfBoundsException(message: "Невозможно создать назначение оплаты с кодом: $code", code: 422);
        }
    }

    /**
     * Возвращает код назначения оплаты
     *
     * @return int
     */
    public function getCode(): int
    {
        return $this->code;
    }

    /**
     * Возвращает наименование назначения оплаты
     *
     * @return string
     */
    public function getTitle(): string
    {
        return self::getAssocList()[$this->getCode()];
    }

    /**
     * Проверка на соответствие кода
     *
     * @param StaffPaymentType $code
     * @return bool
     */
    public function isEqual(StaffPaymentType $code): bool
    {
        return $code->getCode() === $this->getCode();
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return (string)$this->code;
    }

    /**
     * Возвращает массив типов оплаты в виде целых чисел
     *
     * @return int[]
     */
    public static function getCodeList(): array
    {
        return [
            self::UNKNOWN,
            self::PAY,
            self::PROLONG,
            self::PAY_ALL,
            self::RESTRUCTURING,
            self::PAY_RESTRUCTURING,
        ];
    }

    /**
     * Список типов оплаты
     *
     * @return string[]
     */
    public static function getAssocList(): array
    {
        return [
            self::UNKNOWN => 'Неизвестный платеж',
            self::PAY => 'Оплата',
            self::PROLONG => 'Продление',
            self::PAY_ALL => 'Полная оплата',
            self::RESTRUCTURING => 'Оформление реструктуризации',
            self::PAY_RESTRUCTURING => 'Оплата по реструктуризации',
        ];
    }

    /**
     * Возвращает массив типов оплаты в виде объекта
     *
     * @return self[]
     */
    public static function getList(): array
    {
        return [
            new self(code: self::UNKNOWN),
            new self(code: self::PAY),
            new self(code: self::PROLONG),
            new self(code: self::PAY_ALL),
            new self(code: self::RESTRUCTURING),
            new self(code: self::PAY_RESTRUCTURING),
        ];
    }

    /**
     * Проверка на соответствие UNKNOWN
     *
     * @return bool
     */
    public function isUnknown(): bool
    {
        return self::UNKNOWN === $this->getCode();
    }

    /**
     * Проверка на соответствие PAY
     *
     * @return bool
     */
    public function isPay(): bool
    {
        return self::PAY === $this->getCode();
    }

    /**
     * Проверка на соответствие PROLONG
     *
     * @return bool
     */
    public function isProlong(): bool
    {
        return self::PROLONG === $this->getCode();

    }

    /**
     * Проверка на соответствие PAY_ALL
     *
     * @return bool
     */
    public function isPayAll(): bool
    {
        return self::PAY_ALL === $this->getCode();
    }

    /**
     * Проверка на соответствие RESTRUCTURING
     *
     * @return bool
     */
    public function isRestructuring(): bool
    {
        return self::RESTRUCTURING === $this->getCode();
    }

    /**
     * Проверка на соответствие PAY_RESTRUCTURING
     *
     * @return bool
     */
    public function isPayRestructuring(): bool
    {
        return self::PAY_RESTRUCTURING === $this->getCode();
    }

    /**
     * Возвращает Type::PAY
     *
     * @return self
     */
    public static function makeUnknown(): self
    {
        return new self(self::UNKNOWN);
    }

    /**
     * Возвращает Type::PAY
     *
     * @return self
     */
    public static function makePay(): self
    {
        return new self(self::PAY);
    }

    /**
     * Возвращает Type::PROLONG
     *
     * @return self
     */
    public static function makeProlong(): self
    {
        return new self(self::PROLONG);
    }

    /**
     * Возвращает Type::PAY_ALL
     *
     * @return self
     */
    public static function makePayAll(): self
    {
        return new self(self::PAY_ALL);
    }

    /**
     * Возвращает Type::RESTRUCTURING
     *
     * @return self
     */
    public static function makeRestructuring(): self
    {
        return new self(self::RESTRUCTURING);
    }

    /**
     * Возвращает Type::PAY_RESTRUCTURING
     *
     * @return self
     */
    public static function makePayRestructuring(): self
    {
        return new self(self::PAY_RESTRUCTURING);
    }

    /**
     * Возвращаемое значение для хранения в базе данных в необработанном виде.
     *
     * @return string
     */
    public function rawValue(): string
    {
        return $this->__toString();
    }

    /**
     * Возвращает связанный тип PDO
     *
     * @return int
     */
    public function rawType(): int
    {
        return PDO::PARAM_INT;
    }

    /**
     * @param $value
     * @param DatabaseInterface $db
     * @return static
     * @throws OutOfBoundsException
     */
    public static function typecast($value, DatabaseInterface $db): self
    {
        return new static($value);
    }

    /**
     * Возвращает true если тип —
     * продление/закрытие,
     * реструктуризация,
     * оплата по реструктуризации.
     *
     * @return bool
     */
    public function isPayForProlong(): bool
    {
        return $this->isProlong() || $this->isRestructuring() || $this->isPayRestructuring();
    }
}
