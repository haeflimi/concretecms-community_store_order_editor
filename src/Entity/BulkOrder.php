<?php
namespace CommunityStoreOrderEditor\Entity;

use DateTime;
use Doctrine\ORM\Mapping as ORM;

/**
 * One order created by a bulk run. The unique (batchId, uID) pair makes a batch idempotent: a double submit or a
 * resumed run creates every user's order once.
 *
 * @ORM\Entity()
 * @ORM\Table(name="csOrderEditorBulkOrders",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="csoe_bulk_user", columns={"batchId", "uID"})},
 *     indexes={@ORM\Index(name="csoe_bulk_batch", columns={"batchId"}), @ORM\Index(name="csoe_bulk_order", columns={"oID"})}
 * )
 */
class BulkOrder
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue
     */
    protected $id;

    /** @ORM\Column(type="string", length=64) */
    protected $batchId;

    /** @ORM\Column(type="string", length=190) */
    protected $label;

    /** @ORM\Column(type="integer") */
    protected $uID;

    /** @ORM\Column(type="integer") */
    protected $oID;

    /** @ORM\Column(type="integer") */
    protected $byUID;

    /** @ORM\Column(type="datetime") */
    protected $createdAt;

    public function __construct(string $batchId, string $label, int $uID, int $oID, int $byUID)
    {
        $this->batchId = $batchId;
        $this->label = mb_substr($label, 0, 190);
        $this->uID = $uID;
        $this->oID = $oID;
        $this->byUID = $byUID;
        $this->createdAt = new DateTime();
    }

    public function getID(): int
    {
        return (int) $this->id;
    }

    public function getBatchId(): string
    {
        return $this->batchId;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function getUserID(): int
    {
        return (int) $this->uID;
    }

    public function getOrderID(): int
    {
        return (int) $this->oID;
    }

    public function getByUserID(): int
    {
        return (int) $this->byUID;
    }

    public function getCreatedAt(): DateTime
    {
        return $this->createdAt;
    }
}
