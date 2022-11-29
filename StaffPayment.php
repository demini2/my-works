<?php
declare(strict_types=1);

namespace Glavfinans\Core\Entity\StaffPayment;

use Cycle\Annotated\Annotation as Cycle;
use DateTimeInterface;
use Glavfinans\Core\Entity\IncomingTransfer\PaymentDestination;
use Glavfinans\Core\Entity\Staff\Staff;
use Glavfinans\Core\Loan\InternalStatus;
use Glavfinans\Core\Money;

/**
 * Сущность описывает таблицу staff_payment - хранит платежи по софт-коллекторам
 *
 * @package Glavfinans\Core\Entity\StaffPayment
 */
#[Cycle\Entity(role: StaffPayment::class, repository: StaffPaymentRepository::class, table: 'staff_payment')]
class StaffPayment
{
    /** Id записи */
    #[Cycle\Column(type: 'primary', nullable: false)]
    private ?int $id = null;

    /** Id займа */
    #[Cycle\Column(type: 'integer', nullable: false)]
    private int $loanId;

    /** ID платежа */
    #[Cycle\Column(type: 'integer', nullable: true, default: null)]
    private ?int $incomingTransferId = null;

    /** Сумма платежа */
    #[Cycle\Column(type: 'integer', nullable: false, typecast: Money::class)]
    private Money $sum;

    /** Время платежа */
    #[Cycle\Column(type: 'datetime', nullable: false)]
    private DateTimeInterface $paymentDate;

    /** @var int */
    #[Cycle\Column(type: 'integer', nullable: false)]
    private int $staffId;

    /** Объект сотрудника */
    #[Cycle\Relation\BelongsTo(target: Staff::class, innerKey: 'staffId', nullable: false)]
    private Staff $staff;

    /** Staff smena Id */
    #[Cycle\Column(type: 'integer', nullable: false)]
    private int $smenaId;

    /** Тип платежа */
    #[Cycle\Column(type: 'tinyInteger(1)', default: 0, typecast: StaffPaymentType::class)]
    private StaffPaymentType $type;

    /** Назначение */
    #[Cycle\Column(type: 'string', nullable: true, default: null, typecast: PaymentDestination::class)]
    private ?PaymentDestination $destination = null;

    /** Кол-во дней просрочки */
    #[Cycle\Column(type: 'integer', nullable: true, default: null)]
    private ?int $overdueDays = null;

    /** Внутренний статус займа при поступлении платежа */
    #[Cycle\Column(type: 'string', nullable: true, default: null, typecast: InternalStatus::class)]
    private ?InternalStatus $internalStatus = null;

    /**
     * @param int $loanId
     * @param Money $sum
     * @param DateTimeInterface $paymentDate
     * @param Staff $staff
     * @param int $smenaId
     * @param PaymentDestination|null $destination
     * @param StaffPaymentType|null $type
     * @param int|null $incomingTransferId
     * @param int|null $overdueDays
     * @param InternalStatus|null $internalStatus
     */
    public function __construct(
        int                 $loanId,
        Money               $sum,
        DateTimeInterface   $paymentDate,
        Staff               $staff,
        int                 $smenaId,
        ?PaymentDestination $destination = null,
        ?StaffPaymentType   $type = null,
        ?int                $incomingTransferId = null,
        ?int                $overdueDays = null,
        ?InternalStatus     $internalStatus = null,
    ) {
        $this->loanId = $loanId;
        $this->sum = $sum;
        $this->paymentDate = $paymentDate;
        $this->staff = $staff;
        $this->staffId = $this->staff->getId();
        $this->smenaId = $smenaId;
        $this->type = null === $type ? StaffPaymentType::makeUnknown() : $type;
        $this->incomingTransferId = $incomingTransferId;
        $this->destination = $destination;
        $this->overdueDays = $overdueDays;
        $this->internalStatus = $internalStatus;
    }

    /**
     * Возвращает Id записи
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Возвращает Id займа
     *
     * @return int
     */
    public function getLoanId(): int
    {
        return $this->loanId;
    }

    /**
     * Устанавливает Id займа
     *
     * @param int $loanId
     */
    public function setLoanId(int $loanId): void
    {
        $this->loanId = $loanId;
    }

    /**
     * Возвращает Id платежа
     *
     * @return int|null
     */
    public function getIncomingTransferId(): ?int
    {
        return $this->incomingTransferId;
    }

    /**
     * Устанавливает Id платежа
     *
     * @param int|null $incomingTransferId
     */
    public function setIncomingTransferId(?int $incomingTransferId): void
    {
        $this->incomingTransferId = $incomingTransferId;
    }

    /**
     * Возвращает сумму платежа
     *
     * @return Money
     */
    public function getSum(): Money
    {
        return $this->sum;
    }

    /**
     * Устанавливает сумму платежа
     *
     * @param Money $sum
     */
    public function setSum(Money $sum): void
    {
        $this->sum = $sum;
    }

    /**
     * Возвращает дату платежа
     *
     * @return DateTimeInterface
     */
    public function getPaymentDate(): DateTimeInterface
    {
        return $this->paymentDate;
    }

    /**
     * Устанавливает дату платежа
     *
     * @param DateTimeInterface $paymentDate
     */
    public function setPaymentDate(DateTimeInterface $paymentDate): void
    {
        $this->paymentDate = $paymentDate;
    }

    /**
     * @param int|null $staffId
     */
    public function setStaffId(?int $staffId): void
    {
        $this->staffId = $staffId;
    }

    /**
     * @return int|null
     */
    public function getStaffId(): ?int
    {
        return $this->staffId;
    }

    /**
     * Возвращает Staff
     *
     * @return Staff
     */
    public function getStaff(): Staff
    {
        return $this->staff;
    }

    /**
     * Принимает Staff
     *
     * @param Staff $staff
     */
    public function setStaff(Staff $staff): void
    {
        $this->staff = $staff;
    }

    /**
     * Возвращает Id смены
     *
     * @return int
     */
    public function getSmenaId(): int
    {
        return $this->smenaId;
    }

    /**
     * Устанавливает Id смены
     *
     * @param int $smenaId
     */
    public function setSmenaId(int $smenaId): void
    {
        $this->smenaId = $smenaId;
    }

    /**
     * Возвращает тип платежа
     *
     * @return StaffPaymentType
     */
    public function getType(): StaffPaymentType
    {
        return $this->type;
    }

    /**
     * Устанавливает тип платежа
     *
     * @param StaffPaymentType $type
     */
    public function setType(StaffPaymentType $type): void
    {
        $this->type = $type;
    }

    /**
     * Возвращает назначение платежа
     *
     * @return PaymentDestination|null
     */
    public function getDestination(): ?PaymentDestination
    {
        return $this->destination;
    }

    /**
     * Устанавливает назначение платежа
     *
     * @param PaymentDestination|null $destination
     */
    public function setDestination(?PaymentDestination $destination): void
    {
        $this->destination = $destination;
    }

    /**
     * Возвращает внутренний статус займа при поступлении платежа
     *
     * @return InternalStatus|null
     */
    public function getInternalStatus(): ?InternalStatus
    {
        return $this->internalStatus;
    }

    /**
     * Устанавливает внутренний статус займа при поступлении платежа
     *
     * @param InternalStatus|null $internalStatus
     */
    public function setInternalStatus(?InternalStatus $internalStatus): void
    {
        $this->internalStatus = $internalStatus;
    }

    /**
     * Возвращает кол-во дней просрочки платежа
     *
     * @return int|null
     */
    public function getOverdueDays(): ?int
    {
        return $this->overdueDays;
    }

    /**
     * Устанавливает кол-во дней просрочки платежа
     *
     * @param int|null $overdueDays
     */
    public function setOverdueDays(?int $overdueDays): void
    {
        $this->overdueDays = $overdueDays;
    }
}
