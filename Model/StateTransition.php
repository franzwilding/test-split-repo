<?php
/**
 * Created by PhpStorm.
 * User: stefankamsker
 * Date: 19.09.18
 * Time: 15:16
 */

namespace UniteCMS\CoreBundle\Model;

use Symfony\Component\Validator\Constraints as Assert;

use UniteCMS\CoreBundle\Validator\Constraints\ValidIdentifier;

/**
 * We use this model only for validation!
 * 
 * @Assert\GroupSequence({"StateTransition", "Strict"})
 */
class StateTransition
{
    /**
     * @var string
     * @Assert\Type(type="string", message="workflow_invalid_transition")
     * @Assert\NotBlank(message="not_blank")
     * @Assert\Length(max="30", maxMessage="too_long", groups={"Strict"})
     * @ValidIdentifier(message="invalid_characters", groups={"Strict"})
     */
    private $identifier;

    /**
     * @var string
     * @Assert\Type(type="string", message="workflow_invalid_transition")
     * @Assert\NotBlank(message="not_blank")
     * @Assert\Length(max="255", maxMessage="too_long", groups={"Strict"})
     */
    private $label;

    /**
     * @var array
     * @Assert\Type(type="array", message="workflow_invalid_transition_from")
     * @Assert\NotBlank(message="not_blank")
     */
    private $froms;

    /**
     * @var string
     * @Assert\Type(type="string", message="workflow_invalid_transition_to")
     * @Assert\NotBlank(message="not_blank")
     */
    private $to;

    public function __construct($identifier, $label, $froms, $to)
    {
        $this->identifier = $identifier;
        $this->label = $label;
        $this->froms = $froms;
        $this->to = $to;
    }

    /**
     * Get identifier
     *
     * @return string
     */
    public function getIdentifier()
    {
        return $this->identifier;
    }

    /**
     * @param string $identifier
     *
     * @return StateTransition
     */
    public function setIdentifier($identifier)
    {
        $this->identifier = $identifier;

        return $this;
    }

    /**
     * @return string
     */
    public function getLabel()
    {
        return $this->label;
    }

    /**
     * @param string $label
     *
     * @return StateTransition
     */
    public function setLabel($label)
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @return array
     */
    public function getFroms()
    {
        return (array) $this->froms;
    }

    /**
     * @param array $froms
     * 
     * @return StateTransition
     */
    public function setFroms($froms)
    {
        $this->froms = $froms;

        return $this;
    }

    /**
     * @return string
     */
    public function getTo()
    {
        return $this->to;
    }

    /**
     * @param string $to
     *
     * @return StateTransition
     */
    public function setTo($to)
    {
        $this->to = $to;

        return $this;
    }

    /**
     * @return string
     */
    public function __toString()
    {
        return $this->label;
    }

}