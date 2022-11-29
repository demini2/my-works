<?php
declare(strict_types=1);

namespace Glavfinans\Controller\Headquarter;

use Exception;
use Glavfinans\Core\Entity\StaffPayment\StaffPaymentRepositoryInterface;
use Glavfinans\Core\Entity\StaffPayment\StaffPaymentSearchDTO;
use Glavfinans\Core\Kernel\Attribute\DataClass;
use Glavfinans\Core\Kernel\Attribute\Form;
use Glavfinans\Core\Kernel\Attribute\IntParam;
use Glavfinans\Core\Kernel\Attribute\Permission;
use Glavfinans\Core\Kernel\Attribute\StrParam;
use Glavfinans\Core\Paginator\CallbackPaginator;
use Glavfinans\Core\Paginator\Paginator;
use Glavfinans\Core\View\ViewInterface;
use Glavfinans\Form\StaffPaymentSearchForm;
use Staff;
use StaffPaymentAdapter;
use Symfony\Component\Form\FormInterface;
use ViewFactory;

/**
 * Контроллер для платежей по софт-коллекторам
 */
class StaffPaymentController
{
    /**
     * Вывод всех платежей по софт-коллекторам
     *
     * @param StaffPaymentRepositoryInterface $staffPaymentRepository
     * @param StaffPaymentAdapter $staffPaymentAdapter
     * @param ViewFactory $viewFactory
     * @param Paginator $paginator
     * @param FormInterface $staffForm
     * @param int $page
     * @param string $sort
     * @param string $direction
     * @return ViewInterface
     * @throws Exception
     */
    #[Permission(Staff::CAN_VIEW_INDEX_STAFF_PAYMENT)]
    public function index(
        StaffPaymentRepositoryInterface                                                                 $staffPaymentRepository,
        StaffPaymentAdapter                                                                             $staffPaymentAdapter,
        ViewFactory                                                                                     $viewFactory,
        Paginator                                                                                       $paginator,
        #[Form(StaffPaymentSearchForm::class)] #[DataClass(StaffPaymentSearchDTO::class)] FormInterface $staffForm,
        #[IntParam('page', required: false, default: 1)] int                                            $page,
        #[StrParam('sort', required: false, default: 'id')] string                                      $sort,
        #[StrParam('direction', required: false, default: 'DESC')] string                               $direction,
    ): ViewInterface {
        $limit = 20;
        $offset = ($page - 1) * $limit;

        $fCount = function () use ($staffForm, $staffPaymentRepository) {
            return $staffPaymentRepository->countStaffPayment(staffPaymentSearchDTO: $staffForm->getData());
        };

        $fData = function ($offset, $limit) use ($sort, $direction, $staffPaymentAdapter, $staffForm) {
            return $staffPaymentAdapter->getDtoByStaffPayment(
                offset: $offset,
                limit: $limit,
                sort: $sort,
                direction: $direction,
                staffPaymentSearchDTO: $staffForm->getData(),
            );
        };

        $paginator->fill(
            target: new CallbackPaginator(countFunction: $fCount, dataFunction: $fData),
            limit: $limit,
            offset: $offset
        );

        return $viewFactory->makeView(
            name: 'Headquarter/StaffPayment/index',
            data: [
                'paginator' => $paginator,
                'staffPaymentSearchForm' => $staffForm->createView(),
            ]
        );
    }
}
