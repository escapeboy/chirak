"""Request models for the worker API (docs/worker-api.md)."""
from typing import Literal

from pydantic import BaseModel, Field, model_validator

RUN_ID_PATTERN = r"^[a-z0-9][a-z0-9-]{2,63}$"
INVALID = "__invalid__"


class Field_(BaseModel):
    name: str = Field(min_length=1, max_length=64)
    type: Literal["enum", "bool"]
    primary: bool = False
    values: list[str] | None = None

    @model_validator(mode="after")
    def _check(self):
        if self.type == "enum" and not self.values:
            raise ValueError(f"enum field '{self.name}' needs values")
        if self.primary and self.type != "enum":
            raise ValueError("the primary field must be an enum")
        return self


class TaskSpec(BaseModel):
    name: str
    description: str
    languages: list[str] = Field(default_factory=lambda: ["en"])
    fields: list[Field_] = Field(min_length=1)
    guidelines: str = ""

    @model_validator(mode="after")
    def _check(self):
        if sum(1 for f in self.fields if f.primary) != 1:
            raise ValueError("exactly one field must have primary=true")
        if len({f.name for f in self.fields}) != len(self.fields):
            raise ValueError("duplicate field names")
        return self

    @property
    def primary(self) -> Field_:
        return next(f for f in self.fields if f.primary)


class SeedExample(BaseModel):
    input: str
    output: dict


class TargetSlice(BaseModel):
    name: str
    n: int = Field(gt=0)
    guidance: str = ""


class GenerateRequest(BaseModel):
    run_id: str = Field(pattern=RUN_ID_PATTERN)
    purpose: Literal["eval", "train", "pool"]
    iteration: int = Field(default=1, ge=1)
    task_spec: TaskSpec
    seed_examples: list[SeedExample] = Field(default_factory=list)
    n: int = Field(gt=0, le=20000)
    target_slices: list[TargetSlice] | None = None
    avoid_near: list[str] = Field(default_factory=list)


class LoraConfig(BaseModel):
    r: int = 16
    alpha: int = 16
    dropout: float = 0.05
    target_modules: list[str] = Field(
        default_factory=lambda: ["q_proj", "k_proj", "v_proj", "o_proj", "gate_proj", "up_proj", "down_proj"]
    )


class TrainRequest(BaseModel):
    run_id: str = Field(pattern=RUN_ID_PATTERN)
    iteration: int = Field(default=1, ge=1)
    task_spec: TaskSpec
    train_files: list[str] = Field(min_length=1)
    base_model: str | None = None
    lora: LoraConfig = Field(default_factory=LoraConfig)
    epochs: int = Field(default=2, ge=1, le=20)
    lr: float = Field(default=2e-4, gt=0)
    max_seq_len: int = Field(default=1024, ge=64, le=8192)


class EvaluateRequest(BaseModel):
    run_id: str = Field(pattern=RUN_ID_PATTERN)
    eval_file: str
    model: str
    task_spec: TaskSpec
    label: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")


class ServeRequest(BaseModel):
    name: str = Field(pattern=r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
    adapter_path: str


class PredictRequest(BaseModel):
    model: str
    task_spec: TaskSpec
    input: str
