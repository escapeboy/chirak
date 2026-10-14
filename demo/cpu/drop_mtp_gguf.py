"""Fix a qwen35 GGUF converted from a text-only checkpoint whose config still announced the MTP layer
(merge_adapter.py before it reset mtp_num_hidden_layers): block_count and recurrent_layers counted one
block more than the file has, so llama.cpp refused it ("blk.32 not found"). Writes a corrected copy.

Usage: uvx --from gguf==0.17.1 python drop_mtp_gguf.py <in.gguf> <out.gguf>
"""
import sys

import gguf
from gguf.scripts.gguf_new_metadata import MetadataDetails, copy_with_new_metadata

reader = gguf.GGUFReader(sys.argv[1], "r")
arch = reader.fields[gguf.Keys.General.ARCHITECTURE].contents()
n = reader.fields[f"{arch}.block_count"].contents()
n_tensor_blocks = 1 + max(int(t.name.split(".")[1]) for t in reader.tensors if t.name.startswith("blk."))
nextn = reader.fields.get(f"{arch}.nextn_predict_layers")
extra = nextn.contents() if nextn else 0
assert n - extra == n_tensor_blocks, (n, extra, n_tensor_blocks)

recurrent = reader.fields[f"{arch}.attention.recurrent_layers"].contents()[:n_tensor_blocks]
new = {
    f"{arch}.block_count": MetadataDetails(gguf.GGUFValueType.UINT32, n_tensor_blocks),
    f"{arch}.nextn_predict_layers": MetadataDetails(gguf.GGUFValueType.UINT32, 0),
    f"{arch}.attention.recurrent_layers": MetadataDetails(gguf.GGUFValueType.ARRAY, recurrent, sub_type=gguf.GGUFValueType.BOOL),
}
writer = gguf.GGUFWriter(sys.argv[2], arch=arch, endianess=reader.endianess)
alignment = reader.fields.get(gguf.Keys.General.ALIGNMENT)
if alignment is not None:
    writer.data_alignment = alignment.contents()
copy_with_new_metadata(reader, writer, new, [])
print({"blocks": n_tensor_blocks, "recurrent_layers": len(recurrent)})
