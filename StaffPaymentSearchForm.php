<?php

namespace Glavfinans\Form;

use DateFmt;
use DateTimeImmutable;
use Glavfinans\Core\Entity\IncomingTransfer\PaymentDestination;
use Glavfinans\Core\Entity\Staff\IStaffRepository;
use Glavfinans\Core\Entity\StaffPayment\StaffPaymentType;
use Glavfinans\Core\Staff\Role;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\DateTimeType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Класс формы для поиска по StaffPayment
 */
class StaffPaymentSearchForm extends AbstractType
{
    /**
     * @param IStaffRepository $staffRepository
     */
    public function __construct(
        private IStaffRepository $staffRepository,
    ) {
    }

    /**
     * Формы для поиска по StaffPayment
     *
     * @param FormBuilderInterface $builder
     * @param array $options
     * @return void
     */
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $getStaffId = function () {
            return $this->staffRepository->findNameAndIdByRole(role: Role::makeSoftCollector());
        };

        $getDestination = function () {
            $destination = [];
            /** @var PaymentDestination $makeDestination */
            foreach (PaymentDestination::getListOfSoftGroup() as $makeDestination) {
                $destination[$makeDestination->getName()] = $makeDestination;
            }

            return $destination;
        };

        $getType = function () {
            $type = [];
            foreach (StaffPaymentType::getList() as $makeType) {
                $type[$makeType->getTitle()] = $makeType;
            }

            return $type;
        };

        $builder
            ->add('loanId', IntegerType::class, [
                'label' => 'Номер займа',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'off',
                ],
            ])
            ->add('type', ChoiceType::class, [
                'label' => 'Тип платежа',
                'multiple' => false,
                'expanded' => false,
                'required' => false,
                'placeholder' => '',
                'choices' => $getType(),
                'label_html' => true,
                'choice_attr' => function () {
                    return ['class' => 'btn-check'];
                },
                'attr' => [
                    'class' => 'btn-group',
                ]])
            ->add('destination', ChoiceType::class, [
                'label' => 'Назначение платежа',
                'multiple' => false,
                'expanded' => false,
                'required' => false,
                'placeholder' => '',
                'choices' => $getDestination(),
                'label_html' => true,
                'choice_attr' => function () {
                    return ['class' => 'btn-check'];
                },
                'attr' => [
                    'class' => 'btn-group',
                ],
            ])
            ->add('sum', IntegerType::class, [
                'label' => 'Сумма платежа',
                'required' => false,
                'attr' => [
                    'autocomplete' => 'off',
                ],
            ])
            ->add('paymentDate', DateTimeType::class, [
                'label' => 'Дата платежа',
                'required' => false,
                'widget' => 'single_text',
                'input' => 'datetime_immutable',
                'attr' => [
                    'max' => (new DateTimeImmutable())->format(DateFmt::DT_DB),
                ],
            ])
            ->add('staffId', ChoiceType::class, [
                'label' => 'Сотрудник',
                'multiple' => false,
                'expanded' => false,
                'required' => false,
                'placeholder' => '',
                'choices' => $getStaffId(),
                'label_html' => true,
                'choice_attr' => function () {
                    return ['class' => 'btn-check'];
                },
                'attr' => [
                    'class' => 'btn-group',
                ],
            ])
            ->setMethod('POST');
    }

    /**
     * Отключаем имя формы, чтобы поля приходили просто по именам без префикса формы
     */
    public function getBlockPrefix(): string
    {
        return 's';
    }
}
